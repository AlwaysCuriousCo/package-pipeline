<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Widgets\GettingStarted;
use App\Models\Download;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fresh install to a first installed package, without leaving the panel
 * except for GitHub's own screens.
 */
class FirstRunOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.github.app', [
            'id' => null,
            'private_key' => null,
            'slug' => null,
            'api_url' => 'https://api.github.com',
            'webhook_secret' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * A source with four repositories: a library, an application, a fork,
     * and one with no composer.json at all.
     */
    private function sourceWithRepositories(): Source
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
                ['id' => 2, 'full_name' => 'acme/site', 'html_url' => 'https://github.com/acme/site'],
                ['id' => 3, 'full_name' => 'acme/docs', 'html_url' => 'https://github.com/acme/docs'],
                ['id' => 4, 'full_name' => 'acme/framework', 'html_url' => 'https://github.com/acme/framework', 'fork' => true],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json' => Http::response(json_encode(['name' => 'acme/widget-kit', 'type' => 'library'])),
            'api.github.com/repos/acme/site/contents/composer.json' => Http::response(json_encode(['name' => 'acme/site', 'type' => 'project'])),
            'api.github.com/repos/acme/docs/contents/composer.json' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/acme/framework/contents/composer.json' => Http::response(json_encode(['name' => 'laravel/framework', 'type' => 'library'])),
        ]);

        return Source::factory()->withToken()->create(['account' => 'acme']);
    }

    public function test_a_new_install_is_greeted_with_three_steps(): void
    {
        Livewire::test(GettingStarted::class)
            ->assertSee('Getting started')
            ->assertSee('Connect GitHub')
            ->assertSee('also registers this registry&#039;s GitHub App', false)
            ->assertSee('Import your packages')
            ->assertSee('Install one')
            ->assertSeeHtml('wire:click="mountAction(\'connectGithub\'')
            // Not until there is something to import from.
            ->assertDontSee('?action=importFromSource', false);
    }

    public function test_each_step_hands_over_to_the_next_once_the_last_is_done(): void
    {
        $source = Source::factory()->create();

        Livewire::test(GettingStarted::class)
            ->assertDontSeeHtml('wire:click="mountAction(\'connectGithub\'')
            ->assertSeeHtml(e(PackageResource::getUrl('index', ['action' => 'importFromSource'])));

        $package = Package::factory()->create(['source_id' => $source->id]);

        Livewire::test(GettingStarted::class)
            ->assertSee("{$package->name}&#039;s page has the composer commands", false)
            ->assertSeeHtml(e(PackageResource::getUrl('view', ['record' => $package])));
    }

    public function test_the_steps_go_away_once_a_package_has_been_installed(): void
    {
        $this->assertTrue(GettingStarted::canView());

        $package = Package::factory()->create();
        Download::query()->create(['package_id' => $package->id, 'version' => '1.0.0', 'created_at' => now()]);

        $this->assertFalse(GettingStarted::canView());
    }

    public function test_dismissing_the_steps_puts_them_away_for_that_user_only(): void
    {
        $user = auth()->user();
        $colleague = User::factory()->superAdmin()->create();

        Livewire::test(GettingStarted::class)
            ->assertActionExists('dismiss')
            ->callAction('dismiss')
            ->assertNotified('Getting started dismissed')
            ->assertRedirect(Dashboard::getUrl());

        // Stored on the account, so it holds on the next request and on any
        // other browser.
        $this->assertNotNull($user->refresh()->getting_started_dismissed_at);
        // Dashboard widgets load lazily, so the page names them rather than
        // rendering them.
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('App\\Filament\\Widgets\\GettingStarted', false);
        $this->assertFalse(GettingStarted::canView());

        $this->actingAs($colleague);

        $this->assertTrue(GettingStarted::canView());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('App\\Filament\\Widgets\\GettingStarted', false);
    }

    public function test_an_empty_package_list_says_how_packages_get_here(): void
    {
        Livewire::test(ListPackages::class)
            ->assertSee('Add your first package')
            ->assertSee('Connect the GitHub account your packages live in')
            ->assertSee('Connect GitHub account')
            ->assertSee('Add by URL');

        $this->sourceWithRepositories();

        Livewire::test(ListPackages::class)
            ->assertSee('Import several at once from a connected account')
            ->assertDontSee('Connect GitHub account');
    }

    public function test_importing_from_the_only_source_ticks_its_likely_packages(): void
    {
        $source = $this->sourceWithRepositories();

        Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDataSet([
                'source_id' => $source->id,
                // The library — not the application, not the fork of somebody
                // else's, and not the repository with no composer.json.
                'projects' => ['https://github.com/acme/widgets'],
            ])
            // Likely packages first, under their Composer names.
            ->assertSchemaComponentExists('projects', checkComponentUsing: fn (Select $field): bool => $field->getOptions() === [
                'With a composer.json' => [
                    'https://github.com/acme/framework' => 'laravel/framework (acme/framework)',
                    'https://github.com/acme/site' => 'acme/site (acme/site)',
                    'https://github.com/acme/widgets' => 'acme/widget-kit (acme/widgets)',
                ],
                'Other repositories' => [
                    'https://github.com/acme/docs' => 'acme/docs',
                ],
            ]);
    }

    public function test_a_repository_already_onboarded_is_not_ticked_again(): void
    {
        $source = $this->sourceWithRepositories();
        Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);

        Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDataSet(['source_id' => $source->id, 'projects' => []]);
    }

    public function test_connecting_hands_over_to_importing_from_that_source(): void
    {
        Source::factory()->withToken()->create(['account' => 'other']);
        $source = $this->sourceWithRepositories();

        // The link "Connected acme" carries. Filament reads it off the URL and
        // mounts the action from the browser as the page loads.
        Livewire::withQueryParams(['action' => 'importFromSource', 'actionArguments' => ['source' => $source->id]]);

        Livewire::test(ListPackages::class)
            ->assertSeeHtml("mountAction('importFromSource' ,  JSON.parse('{\\u0022source\\u0022:{$source->id}}')")
            ->mountAction('importFromSource', ['source' => $source->id])
            ->assertActionDataSet(['source_id' => $source->id, 'projects' => ['https://github.com/acme/widgets']]);
    }

    public function test_manifests_are_read_for_a_bounded_number_of_repositories_and_remembered(): void
    {
        $repositories = collect(range(1, 120))->map(fn (int $i): array => [
            'id' => $i, 'full_name' => "acme/repo-{$i}", 'html_url' => "https://github.com/acme/repo-{$i}",
        ]);

        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response($repositories->all()),
            'api.github.com/repos/*/contents/composer.json' => Http::response(['message' => 'Not Found'], 404),
        ]);

        Source::factory()->withToken()->create(['account' => 'acme']);

        Livewire::test(ListPackages::class)->mountAction('importFromSource');
        Livewire::test(ListPackages::class)->mountAction('importFromSource');

        $reads = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/contents/composer.json'));

        $this->assertCount(100, $reads);
        $this->assertTrue($reads->every(fn (array $pair): bool => $pair[0]->hasHeader('Authorization', 'Bearer github_pat_source')));
    }
}
