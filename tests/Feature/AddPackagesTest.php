<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Models\Source;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding a package after the first: a repository the GitHub App cannot see
 * yet is granted on GitHub, in a new tab, while the dialog waits.
 */
class AddPackagesTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = 'https://github.com/organizations/acme/settings/installations/42';

    /**
     * The repositories the installation is granted, which GitHub changes
     * while the dialog is open.
     *
     * @var list<string>
     */
    private array $granted = ['widgets'];

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => $pem,
            'api_url' => 'https://api.github.com',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/app/installations/42/access_tokens') => Http::response(['token' => 'ghs_installation', 'expires_at' => now()->addHour()->toIso8601String()]),
                str_contains($url, '/app/installations/42') => Http::response([
                    'account' => ['login' => 'acme', 'type' => 'Organization'],
                    'repository_selection' => 'selected',
                    'html_url' => self::SETTINGS,
                ]),
                str_contains($url, '/installation/repositories') => Http::response([
                    'total_count' => count($this->granted),
                    'repositories' => array_map(fn (string $name): array => [
                        'id' => crc32($name),
                        'full_name' => "acme/{$name}",
                        'html_url' => "https://github.com/acme/{$name}",
                    ], $this->granted),
                ]),
                (bool) preg_match('#/repos/acme/(\w+)/contents/composer.json#', $url, $match) => Http::response(json_encode(['name' => "acme/{$match[1]}", 'type' => 'library'])),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function installedSource(string $selection = 'selected'): Source
    {
        return Source::factory()->create([
            'installation_id' => 42,
            'account' => 'acme',
            'metadata' => ['repository_selection' => $selection, 'repository_count' => 1, 'html_url' => self::SETTINGS],
        ]);
    }

    public function test_a_source_sharing_only_chosen_repositories_starts_on_granting_access(): void
    {
        $this->installedSource();

        Livewire::test(ListPackages::class)
            ->assertActionExists('importFromSource', fn (Action $action): bool => $action->getLabel() === 'Add packages')
            ->mountAction('importFromSource')
            // GitHub's own page for the installation, as the API gave it, in
            // a new tab so the dialog stays open.
            ->assertActionHasUrl(TestAction::make('grantAccess')->schemaComponent('access.grant'), self::SETTINGS)
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('grantAccess')->schemaComponent('access.grant'))
            ->assertActionExists(TestAction::make('refreshAccess')->schemaComponent('access.grant'));
    }

    public function test_refreshing_reads_the_grant_back_and_ticks_the_new_repository(): void
    {
        $source = $this->installedSource();

        $page = Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDataSet(['source_id' => $source->id, 'projects' => ['https://github.com/acme/widgets']]);

        // Granted on GitHub, in the other tab, while the dialog stays open.
        $this->granted[] = 'gadgets';

        $page->callAction(TestAction::make('refreshAccess')->schemaComponent('access.grant'))
            ->assertNotified('2 repositories shared')
            ->assertActionDataSet(['projects' => ['https://github.com/acme/gadgets', 'https://github.com/acme/widgets']]);

        $this->assertSame(2, $source->refresh()->metadata['repository_count']);
    }

    public function test_an_installation_on_all_repositories_has_nothing_to_grant(): void
    {
        $this->installedSource('all');

        Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDoesNotExist(TestAction::make('refreshAccess')->schemaComponent('access.grant'));
    }

    public function test_a_source_with_a_token_has_nothing_to_grant(): void
    {
        Http::fake(['api.github.com/orgs/acme/repos*' => Http::response([])]);
        Source::factory()->withToken()->create(['account' => 'acme']);

        Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDoesNotExist(TestAction::make('refreshAccess')->schemaComponent('access.grant'));
    }

    public function test_with_several_sources_and_none_in_mind_the_dialog_starts_on_choosing(): void
    {
        $this->installedSource();
        Source::factory()->create(['account' => 'other']);

        Livewire::test(ListPackages::class)
            ->mountAction('importFromSource')
            ->assertActionDoesNotExist(TestAction::make('refreshAccess')->schemaComponent('access.grant'));
    }

    public function test_a_source_page_adds_packages_from_that_source(): void
    {
        $source = $this->installedSource();
        Source::factory()->create(['account' => 'other']);

        Livewire::test(ViewSource::class, ['record' => $source->getRouteKey()])
            ->mountAction('importFromSource')
            ->assertActionDataSet(['source_id' => $source->id])
            ->assertActionExists(TestAction::make('refreshAccess')->schemaComponent('access.grant'));
    }
}
