<?php

namespace Tests\Feature;

use App\Filament\Pages\GetStarted;
use App\Filament\Resources\Packages\Actions\ImportFromSourceAction;
use App\Jobs\SyncPackageJob;
use App\Models\Download;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Source;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Wizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * The first run as one wizard: GitHub, packages, their first sync, and an
 * installation — resuming from the registry's own state after each trip to
 * GitHub.
 */
class GetStartedTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'https://packages.acme.test';

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

    private function assertOpensOn(int $step): void
    {
        Livewire::test(GetStarted::class)
            ->assertSchemaComponentExists(
                'onboarding',
                'content',
                fn (Wizard $wizard): bool => $wizard->getStartStep() === $step,
            );
    }

    private function appConfigured(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => $pem,
            'api_url' => 'https://api.github.com',
        ]);
    }

    public function test_it_opens_on_the_first_step_the_registry_has_not_done(): void
    {
        $this->assertOpensOn(1);

        $source = Source::factory()->create();
        $this->assertOpensOn(2);

        $package = Package::factory()->create(['source_id' => $source->id]);
        $this->assertOpensOn(3);

        PackageVersion::factory()->for($package)->create();
        $this->assertOpensOn(4);
    }

    public function test_a_step_in_the_query_string_cannot_skip_one_that_is_not_done(): void
    {
        Livewire::withQueryParams(['step' => 'test']);

        $this->assertOpensOn(1);
    }

    public function test_a_user_who_cannot_see_sources_is_kept_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(GetStarted::getUrl())->assertForbidden();
    }

    public function test_connecting_with_no_app_yet_registers_one_and_marks_the_trip_as_onboarding(): void
    {
        Livewire::test(GetStarted::class)
            ->callAction(TestAction::make('connectGithub')->schemaComponent('onboarding.connect.actions'), data: [
                'owner_type' => 'organization',
                'owner' => 'acme',
                'name' => 'Acme Packages',
            ])
            ->assertRedirect(route('github-app.manifest'));

        $this->assertTrue(session(GetStarted::RETURN_KEY));
    }

    public function test_connecting_with_an_app_goes_to_an_install_that_returns_here(): void
    {
        $this->appConfigured();

        Livewire::test(GetStarted::class)
            ->assertActionHasUrl(
                TestAction::make('connectGithub')->schemaComponent('onboarding.connect.actions'),
                route('sources.connect.new', ['onboarding' => 1]),
            );
    }

    public function test_importing_moves_on_to_the_first_sync(): void
    {
        Queue::fake();

        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json' => Http::response(json_encode(['name' => 'acme/widgets', 'type' => 'library'])),
        ]);

        $source = Source::factory()->withToken()->create(['account' => 'acme']);

        Livewire::test(GetStarted::class)
            ->set('importData', [
                'source_id' => $source->id,
                'projects' => ['https://github.com/acme/widgets'],
                'repository_id' => Repository::default()->id,
                'create_webhook' => false,
            ])
            ->callAction(TestAction::make('importPackages')->schemaComponent('onboarding.import.actions'))
            ->assertRedirect(GetStarted::getUrl());

        $this->assertSame(['acme/widgets'], Package::query()->pluck('name')->all());
        Queue::assertPushed(SyncPackageJob::class);
        $this->assertOpensOn(3);
    }

    public function test_the_sync_poll_is_only_rendered_for_the_step_the_page_mounted_on(): void
    {
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 1)
            ->assertDontSeeHtml('wire:poll');
        $source = Source::factory()->create();
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 2)
            ->assertDontSeeHtml('wire:poll');
        $package = Package::factory()->create(['source_id' => $source->id]);
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 3)
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"');
        PackageVersion::factory()->for($package)->create();
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 4)
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_check_sync_does_not_redirect_when_the_mounted_step_has_not_changed(): void
    {
        Livewire::test(GetStarted::class)->call('checkSync')->assertNoRedirect();
        $source = Source::factory()->create();
        Livewire::test(GetStarted::class)->call('checkSync')->assertNoRedirect();
        $package = Package::factory()->create(['source_id' => $source->id]);
        Livewire::test(GetStarted::class)->call('checkSync')->assertNoRedirect();
        PackageVersion::factory()->for($package)->create();
        Livewire::test(GetStarted::class)->call('checkSync')->assertNoRedirect();
    }

    public function test_check_sync_redirects_when_the_registry_changes_the_mounted_step(): void
    {
        $page = Livewire::test(GetStarted::class);
        $source = Source::factory()->create();
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
        $page = Livewire::test(GetStarted::class);
        $package = Package::factory()->create(['source_id' => $source->id]);
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
        PackageVersion::factory()->for($package)->create();
        $page = Livewire::test(GetStarted::class);
        $package->versions()->delete();
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
    }

    public function test_the_sync_step_waits_for_versions_then_moves_on_by_itself(): void
    {
        $package = Package::factory()->create(['source_id' => Source::factory()->create()->id]);

        $page = Livewire::test(GetStarted::class)
            ->assertSeeText('Waiting for the queue worker to start…')
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"')
            ->call('checkSync')
            ->assertNoRedirect();

        PackageVersion::factory()->for($package)->create();

        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
    }

    /** @param array<string, mixed> $attributes */
    private function syncBatch(Package $package, array $attributes = []): void
    {
        $id = (string) Str::orderedUuid();
        DB::table('job_batches')->insert($attributes + [
            'id' => $id,
            'name' => 'First sync',
            'total_jobs' => 5,
            'pending_jobs' => 3,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'cancelled_at' => null,
            'created_at' => now()->timestamp,
            'finished_at' => null,
        ]);
        $package->forceFill(['sync_batch_id' => $id])->save();
    }

    private function syncingPackage(): Package
    {
        return Package::factory()->create(['source_id' => Source::factory()->create()->id]);
    }

    public function test_the_queued_stage_renders_a_spinner_and_client_side_elapsed_clock(): void
    {
        $this->syncingPackage();
        $page = Livewire::test(GetStarted::class)
            ->assertSeeText('Waiting for the queue worker to start…')
            ->assertSeeText('The registry copies each tagged release from GitHub')
            ->assertSeeText('This is the first sync')
            ->assertSeeText('the background process that runs syncs')
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"')
            ->assertSeeHtml('aria-label="Time waiting for sync"');

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $this->assertCount(1, (new \DOMXPath($dom))->query('//*[local-name()="svg" and @data-role="sync-spinner"]'));
        $clock = (new \DOMXPath($dom))->query('//span[@data-role="sync-elapsed"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $clock);
        $this->assertStringContainsString('setInterval(() => this.tick(), 1000)', $clock->getAttribute('x-data'));
        $this->assertStringContainsString('clearInterval(this.timer)', $clock->getAttribute('x-data'));
        $this->assertStringContainsString('$wire.syncWaitStartedAt', $clock->getAttribute('x-data'));
        $component = $page->instance();
        $this->assertInstanceOf(GetStarted::class, $component);
        $this->assertFalse($component->syncStatus()[0]['hasBatch']);
    }

    public function test_the_discovering_stage_names_the_package(): void
    {
        $package = $this->syncingPackage();
        $this->syncBatch($package, ['total_jobs' => 1, 'pending_jobs' => 1]);
        $page = Livewire::test(GetStarted::class)
            ->assertSeeText("Asking GitHub for {$package->name}'s tags and branches…", false)
            ->assertDontSeeHtml('role="progressbar"');
        $component = $page->instance();
        $this->assertInstanceOf(GetStarted::class, $component);
        $this->assertTrue($component->syncStatus()[0]['discovering']);
    }

    public function test_the_importing_stage_excludes_discovery_from_counts_and_bar_width(): void
    {
        $package = $this->syncingPackage();
        $this->syncBatch($package);
        $page = Livewire::test(GetStarted::class)
            ->assertSeeText('Importing versions: 1 of 4 · building an archive for each')
            ->assertSeeHtml('aria-valuemax="4" aria-valuenow="1"')
            ->assertSeeHtml('max="4" value="1"');
        $component = $page->instance();
        $this->assertInstanceOf(GetStarted::class, $component);
        $status = $component->syncStatus()[0];
        $this->assertSame(1, $status['imported']);
        $this->assertSame(4, $status['total']);
        $this->assertSame(40, $status['progress']);
    }

    public function test_failed_imports_are_shown_inline(): void
    {
        $package = $this->syncingPackage();
        $this->syncBatch($package, ['failed_jobs' => 1]);
        Livewire::test(GetStarted::class)
            ->assertSeeText("1 version couldn't be imported; the remaining versions are still syncing.", false)
            ->assertSeeText('Importing versions: 1 of 4');
    }

    public function test_a_sync_error_stays_inline_without_an_active_spinner(): void
    {
        $package = $this->syncingPackage();
        $package->forceFill(['sync_error' => 'GitHub refused access'])->save();
        Livewire::test(GetStarted::class)
            ->assertSeeText('Failed: GitHub refused access')
            ->assertDontSeeHtml('data-role="sync-spinner"')
            ->assertSeeHtml(e(GetStarted::packageUrl($package)));
    }

    public function test_ready_versions_render_before_the_next_poll_advances(): void
    {
        $package = $this->syncingPackage();
        $page = Livewire::test(GetStarted::class);
        PackageVersion::factory()->for($package)->count(2)->create();
        $page->call('$refresh')->assertSeeText('Ready (2 versions)')
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"')
            ->assertNoRedirect();
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
    }

    public function test_an_unrelated_render_keeps_polling_when_versions_arrive(): void
    {
        $package = Package::factory()->create(['source_id' => Source::factory()->create()->id]);
        $page = Livewire::test(GetStarted::class);
        PackageVersion::factory()->for($package)->create();

        $page->call('$refresh')->assertNoRedirect()
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"');
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
    }

    public function test_versions_arriving_between_check_and_render_keep_the_poll(): void
    {
        $package = Package::factory()->create(['source_id' => Source::factory()->create()->id]);
        $page = Livewire::test(RacingGetStarted::class);
        $page->call('checkSync')->assertNoRedirect()
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"');
        $page->call('checkSync')->assertRedirect(GetStarted::getUrl());
    }

    public function test_automatic_wait_is_bounded_and_can_be_rechecked(): void
    {
        Package::factory()->create(['source_id' => Source::factory()->create()->id]);
        $page = Livewire::test(GetStarted::class);
        $this->travel(301)->seconds();
        $page->call('checkSync')->assertSet('syncWaitExpired', true)
            ->assertDontSeeHtml('wire:poll.3s.visible="checkSync"')
            ->assertSee('Check again');
        $page->call('recheckSync')->assertSet('syncWaitExpired', false)
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"');
    }

    public function test_onboarding_footer_buttons_are_physically_hidden_on_each_step(): void
    {
        for ($step = 1; $step <= 3; $step++) {
            $html = Livewire::test(GetStarted::class)->html();
            $this->assertHiddenOnboardingFooter($html);
            if ($step === 1) {
                Source::factory()->create();
            } elseif ($step === 2) {
                Package::factory()->create();
            }
        }
    }

    private function assertHiddenOnboardingFooter(string $html): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $buttons = (new \DOMXPath($dom))->query('(//div[contains(@class, "fi-sc-wizard-footer")])[1]//button');
        $this->assertCount(2, $buttons);
        foreach ($buttons as $button) {
            if ($button instanceof \DOMElement) {
                $this->assertContains('hidden', explode(' ', $button->getAttribute('class')));
                $this->assertSame('true', $button->getAttribute('aria-disabled'));
            }
        }
    }

    public function test_the_footer_stays_hidden_behind_the_connect_modal_and_inline_picker(): void
    {
        $page = Livewire::test(GetStarted::class)
            ->assertSeeText('Only select repositories')
            // Written for someone who has never met a GitHub App.
            ->assertSeeText('The connection is a GitHub App: a limited, read-only identity')
            ->assertSeeText('Installing it on an account lets that account decide which repositories the registry may read')
            ->mountAction(TestAction::make('connectGithub')->schemaComponent('onboarding.connect.actions'))
            ->assertMountedActionModalSee('Only select repositories');
        $this->assertHiddenOnboardingFooter($page->html());

        Http::fake(['api.github.com/orgs/acme/repos*' => Http::response([])]);
        Source::factory()->withToken()->create(['account' => 'acme']);
        $page = Livewire::test(GetStarted::class)->assertSeeText('Import packages');
        $this->assertHiddenOnboardingFooter($page->html());
    }

    private function inlineSource(): Source
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
                ['id' => 2, 'full_name' => 'acme/site', 'html_url' => 'https://github.com/acme/site'],
                ['id' => 3, 'full_name' => 'acme/fork', 'html_url' => 'https://github.com/acme/fork', 'fork' => true],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json' => Http::response(json_encode(['name' => 'acme/widgets', 'type' => 'library'])),
            'api.github.com/repos/acme/site/contents/composer.json' => Http::response(json_encode(['name' => 'acme/site', 'type' => 'project'])),
            'api.github.com/repos/acme/fork/contents/composer.json' => Http::response(json_encode(['name' => 'acme/fork', 'type' => 'library'])),
        ]);

        return Source::factory()->withToken()->create(['account' => 'acme']);
    }

    public function test_the_inline_picker_shows_preselected_repositories_without_a_modal(): void
    {
        $source = $this->inlineSource();
        $page = Livewire::test(GetStarted::class)
            ->assertSet('importData.source_id', $source->id)
            ->assertSet('importData.projects', ['https://github.com/acme/widgets'])
            ->assertSet('importData.repository_id', Repository::default()->id)
            ->assertSeeText('A package is a reusable PHP library')
            ->assertSeeText('Composer, PHP\'s package manager')
            ->assertSeeText('the file that describes a PHP project')
            ->assertSeeText('the address your projects install these packages from')
            ->assertSeeText('Likely packages are pre-ticked')
            ->assertSeeText('acme/widgets')
            ->assertSeeText('Import packages')
            ->assertSeeText('Default is this registry')
            ->assertSchemaComponentHidden('repository_id', 'importForm')
            ->assertActionDoesNotExist(TestAction::make('importFromSource')->schemaComponent('onboarding.import.actions'))
            ->assertSchemaComponentExists('projects', 'importForm', fn (CheckboxList $field): bool => count($field->getOptions()) === 3);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $this->assertCount(3, (new \DOMXPath($dom))->query('//input[@type="checkbox" and contains(@*[starts-with(name(), "wire:model")], "importData.projects")]'));
    }

    public function test_picker_descriptions_explain_library_application_and_fork_candidacy(): void
    {
        $this->inlineSource();
        Livewire::test(GetStarted::class)
            ->assertSet('importData.projects', ['https://github.com/acme/widgets'])
            ->assertSeeText('Library: composer.json names acme/widgets')
            ->assertSeeText('Application (composer.json type is project)')
            ->assertSeeText('Fork')
            ->assertSchemaComponentExists('projects', 'importForm', fn (CheckboxList $field): bool => $field->getDescriptions() === [
                'https://github.com/acme/fork' => 'Fork',
                'https://github.com/acme/site' => 'Application (composer.json type is project)',
                'https://github.com/acme/widgets' => 'Library: composer.json names acme/widgets',
            ] && $field->getOptions()['https://github.com/acme/widgets'] === 'acme/widgets');
    }

    public function test_picker_descriptions_identify_non_packages_and_already_imported_repositories(): void
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/docs', 'html_url' => 'https://github.com/acme/docs'],
                ['id' => 2, 'full_name' => 'acme/unnamed', 'html_url' => 'https://github.com/acme/unnamed'],
                ['id' => 3, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
            ]),
            'api.github.com/repos/acme/docs/contents/composer.json' => Http::response([], 404),
            'api.github.com/repos/acme/unnamed/contents/composer.json' => Http::response(json_encode(['type' => 'library'])),
            'api.github.com/repos/acme/widgets/contents/composer.json' => Http::response(json_encode(['name' => 'acme/widgets'])),
        ]);
        $source = Source::factory()->withToken()->create(['account' => 'acme']);
        // The picker can stay open while another import lands.
        $page = Livewire::test(GetStarted::class);
        Package::factory()->for($source)->create(['repository' => 'https://github.com/acme/widgets']);
        $page->set('importData.projects', [])
            ->assertSeeText('Not a package: no composer.json with a name')
            ->assertSeeText('Already imported')
            ->assertSchemaComponentExists('projects', 'importForm', fn (CheckboxList $field): bool => $field->getDescriptions() === [
                'https://github.com/acme/unnamed' => 'Not a package: no composer.json with a name',
                'https://github.com/acme/widgets' => 'Already imported',
                'https://github.com/acme/docs' => 'Not a package: no composer.json with a name',
            ]);
        $this->assertSame([], ImportFromSourceAction::defaultData($source->id)['projects']);
    }

    public function test_inline_options_are_memoised_per_request_and_read_again_on_refresh(): void
    {
        $this->inlineSource();
        $page = Livewire::test(GetStarted::class);
        $repositoryReads = fn (): int => count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/orgs/acme/repos')));
        // One listing to scan manifests, one to label options. Repeated field
        // and empty-state evaluations reuse the options within this request.
        $this->assertSame(2, $repositoryReads());
        $page->call('$refresh');
        // A later request reads accessible repositories again; the manifest
        // cache remains the source client's separate existing cache.
        $this->assertSame(3, $repositoryReads());
    }

    public function test_the_source_just_connected_is_preselected_among_several_sources(): void
    {
        $source = $this->inlineSource();
        Source::factory()->withToken()->create(['account' => 'other']);
        Livewire::withQueryParams(['source' => $source->id]);
        Livewire::test(GetStarted::class)
            ->assertSet('importData.source_id', $source->id)
            ->assertSet('importData.projects', ['https://github.com/acme/widgets']);
    }

    public function test_zero_repository_matches_give_guidance_without_opening_a_modal(): void
    {
        Http::fake(['api.github.com/orgs/acme/repos*' => Http::response([])]);
        Source::factory()->withToken()->create(['account' => 'acme']);
        Livewire::test(GetStarted::class)
            ->assertSet('importData.projects', [])
            ->assertSeeText('No repositories were found for this source')
            ->assertSeeText('Check that the GitHub App has access');
    }

    public function test_repositories_without_a_root_manifest_explain_why_none_are_ticked(): void
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/docs', 'html_url' => 'https://github.com/acme/docs'],
            ]),
            'api.github.com/repos/acme/docs/contents/composer.json' => Http::response(['message' => 'Not Found'], 404),
        ]);
        Source::factory()->withToken()->create(['account' => 'acme']);
        Livewire::test(GetStarted::class)->assertSet('importData.projects', [])
            ->assertSeeText('No likely PHP library packages were found')
            ->assertSeeText('You can select a repository manually');
    }

    public function test_a_user_without_package_creation_permission_cannot_import_inline(): void
    {
        Queue::fake();
        $this->inlineSource();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Source', 'View:Source']);
        $this->actingAs($user);
        Livewire::test(GetStarted::class)
            ->assertActionDoesNotExist(TestAction::make('importPackages')->schemaComponent('onboarding.import.actions'));
        $this->assertDatabaseCount('packages', 0);
        Queue::assertNothingPushed();
    }

    public function test_other_steps_do_not_fetch_the_inline_repository_picker(): void
    {
        Source::factory()->withToken()->disconnected()->create();
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 1);
        Source::factory()->withToken()->create();
        $package = Package::factory()->create();
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 3);
        PackageVersion::factory()->for($package)->create();
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 4);
        Http::assertNothingSent();
    }

    public function test_inline_import_requires_a_selection_and_rejects_unknown_repository_urls(): void
    {
        Queue::fake();
        $this->inlineSource();
        Livewire::test(GetStarted::class)->set('importData.projects', [])
            ->callAction(TestAction::make('importPackages')->schemaComponent('onboarding.import.actions'))
            ->assertHasErrors(['importData.projects']);
        Livewire::test(GetStarted::class)->set('importData.projects', ['https://github.com/ungranted/private'])
            ->callAction(TestAction::make('importPackages')->schemaComponent('onboarding.import.actions'))
            ->assertHasErrors(['importData.projects.0']);
        $this->assertDatabaseCount('packages', 0);
        Queue::assertNothingPushed();
    }

    public function test_inline_import_preserves_multiple_composer_destinations(): void
    {
        $this->inlineSource();
        $other = Repository::factory()->create(['name' => 'Tools']);
        Livewire::test(GetStarted::class)->assertSchemaComponentVisible('repository_id', 'importForm')
            ->assertSchemaComponentExists('repository_id', 'importForm', fn (Select $field): bool => isset($field->getOptions()[$other->id]));
    }

    public function test_the_last_step_streams_an_installation_and_passes(): void
    {
        config(['app.url' => self::ROOT]);
        Repository::default()->forceFill(['public' => false])->save();

        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'source_id' => Source::factory()->create()->id,
            'repository_id' => Repository::default()->id,
        ]);
        $package->versions()->create([
            'version' => 'v1.2.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.2.0'],
        ]);

        $zip = $this->zipBytes();

        Http::fake(fn (Request $request) => match (true) {
            str_ends_with($request->url(), '/packages.json') => $request->hasHeader('Authorization')
                ? Http::response(['packages' => [], 'metadata-url' => '/p2/%package%.json'])
                : Http::response(['message' => 'Authentication required.'], 401),
            str_contains($request->url(), '/p2/acme/widgets.json') => Http::response(['minified' => 'composer/2.0', 'packages' => ['acme/widgets' => [
                ['name' => 'acme/widgets', 'version' => 'v1.2.0', 'version_normalized' => '1.2.0.0', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/'.str_repeat('a', 40).'.zip', 'shasum' => sha1($zip)]],
            ]]]),
            str_contains($request->url(), '/dist/acme/widgets/') => Http::response($zip),
            default => Http::response('not found', 404),
        });

        // Livewire flushes each streamed chunk out of the current buffer, so
        // a second one underneath is what catches them.
        ob_start();
        ob_start();

        try {
            $page = Livewire::test(GetStarted::class)
                ->call('autoRunTest');
        } finally {
            $streamed = (string) ob_get_clean();
            $streamed = ob_get_clean().$streamed;
        }

        $this->assertStringContainsString('"name":"installation-test"', $streamed);
        $this->assertStringContainsString('Installation test passed', $streamed);

        $page->assertSet('passed', true)
            ->assertSee('Your registry is ready');

        $this->assertSame(0, Token::query()->count());
    }

    private function zipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', '{"name":"acme/widgets"}');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function test_the_non_package_warning_follows_the_selection_and_import_stays_allowed(): void
    {
        Queue::fake();
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/docs', 'html_url' => 'https://github.com/acme/docs'],
                ['id' => 2, 'full_name' => 'acme/unnamed', 'html_url' => 'https://github.com/acme/unnamed'],
            ]),
            'api.github.com/repos/acme/docs/contents/composer.json' => Http::response([], 404),
            'api.github.com/repos/acme/unnamed/contents/composer.json' => Http::response(json_encode(['type' => 'library'])),
        ]);
        Source::factory()->withToken()->create(['account' => 'acme']);
        $warning = "acme/docs has no composer.json with a package name, so Composer can't install it. You can import it anyway.";
        Livewire::test(GetStarted::class)
            ->assertDontSeeText($warning)
            ->set('importData.projects', ['https://github.com/acme/docs', 'https://github.com/acme/unnamed'])
            ->assertSeeText($warning)
            ->assertSeeText('acme/unnamed has no composer.json with a package name')
            ->set('importData.projects', [])
            ->assertDontSeeText($warning)
            ->set('importData.projects', ['https://github.com/acme/docs'])
            ->set('importData.create_webhook', false)
            ->callAction(TestAction::make('importPackages')->schemaComponent('onboarding.import.actions'))
            ->assertHasNoErrors()->assertRedirect(GetStarted::getUrl());
        $this->assertSame([Package::query()->sole()->id], session(GetStarted::PACKAGES_KEY));
        Queue::assertPushed(SyncPackageJob::class);
    }

    public function test_a_public_destination_is_named_before_import_and_on_the_ready_screen(): void
    {
        config(['app.url' => self::ROOT]);
        $this->inlineSource();
        $warning = 'Default is public: anyone who can reach '.self::ROOT.' can list and install the packages imported into it, without a token.';

        $this->assertTrue(Repository::default()->public);
        Livewire::test(GetStarted::class)
            ->assertSeeText($warning)
            ->assertActionVisible(TestAction::make('makeRepositoryPrivate')->schemaComponent('visibility', 'importForm'));

        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => Repository::default()->id]);
        PackageVersion::factory()->for($package)->create();
        session()->put(GetStarted::PACKAGES_KEY, [$package->id]);
        Livewire::test(GetStarted::class)->set('passed', true)
            ->assertSeeText('This registry is public: anyone who can reach it can install acme/widgets without a token.')
            ->assertDontSeeText('This registry is private');
    }

    public function test_make_it_private_closes_the_default_repository_before_import(): void
    {
        $this->inlineSource();

        Livewire::test(GetStarted::class)
            ->callAction(TestAction::make('makeRepositoryPrivate')->schemaComponent('visibility', 'importForm'))
            ->assertNotified('Default is private')
            ->assertDontSeeText('Default is public')
            ->assertActionDoesNotExist(TestAction::make('makeRepositoryPrivate')->schemaComponent('visibility', 'importForm'));

        $this->assertFalse(Repository::default()->public);
    }

    public function test_only_someone_who_may_edit_the_repository_can_make_it_private(): void
    {
        $this->inlineSource();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Source', 'View:Source', 'Create:Package']);
        $this->actingAs($user);

        Livewire::test(GetStarted::class)
            ->assertSeeText('Default is public')
            ->assertActionDoesNotExist(TestAction::make('makeRepositoryPrivate')->schemaComponent('visibility', 'importForm'));

        $this->assertTrue(Repository::default()->public);
    }

    public function test_every_package_has_its_own_sync_status_and_waits_for_the_last_one(): void
    {
        $source = Source::factory()->create();
        $ready = Package::factory()->for($source)->create(['name' => 'acme/ready']);
        PackageVersion::factory()->for($ready)->count(2)->create();
        $pending = Package::factory()->for($source)->create(['name' => 'acme/pending']);
        $this->syncBatch($pending);
        // A version arriving before its batch finishes is still syncing.
        PackageVersion::factory()->for($pending)->create();
        Package::factory()->for($source)->create(['name' => 'acme/docs', 'sync_error' => 'No installable versions found: no tag or branch contains a composer.json with a "name".']);
        Package::factory()->for($source)->create(['name' => 'acme/failed', 'sync_error' => 'GitHub refused access']);
        $page = Livewire::test(GetStarted::class)->assertSet('mountedStep', 3)
            ->assertSeeText('acme/ready')->assertSeeText('Ready (2 versions)')
            ->assertSeeText('acme/pending')->assertSeeText('Importing versions: 1 of 4')
            ->assertSeeText('acme/docs')->assertSeeText('Not a package: no composer.json with a name')
            ->assertSeeText('acme/failed')->assertSeeText('Failed: GitHub refused access')
            ->call('checkSync')->assertNoRedirect();
        DB::table('job_batches')->where('id', $pending->fresh()->sync_batch_id)
            ->update(['pending_jobs' => 0, 'finished_at' => now()->timestamp]);
        $page->call('checkSync')->assertNoRedirect()->assertSet('mountedStep', 3)
            ->assertSeeText('Remove')->assertSeeText('Continue to the installation test')
            ->assertSeeText("Some repositories can't be installed. Remove them, or carry on: the installation test uses acme/ready.", false)
            ->assertDontSeeHtml('wire:poll');
        $this->assertOpensOn(3);
        $page->call('continueToInstallationTest')->assertRedirect(GetStarted::getUrl());
        $this->assertOpensOn(4);
    }

    public function test_remove_the_last_non_installable_package_advances_to_the_test(): void
    {
        $source = Source::factory()->create();
        $ready = Package::factory()->for($source)->create();
        PackageVersion::factory()->for($ready)->create();
        $docs = Package::factory()->for($source)->create(['sync_error' => 'No installable versions found: no composer.json with a name.']);
        session()->put(GetStarted::PACKAGES_KEY, [$ready->id, $docs->id]);
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 3)
            ->assertSeeText('Not a package')->assertSeeText('Remove')
            ->assertSeeText('Continue to the installation test')->assertDontSeeHtml('wire:poll')
            ->call('removePackage', $docs->id)->assertRedirect(GetStarted::getUrl());
        $this->assertModelMissing($docs);
        $this->assertOpensOn(4);
    }

    public function test_continue_requires_a_settled_batch_with_a_ready_package(): void
    {
        $package = $this->syncingPackage();
        Livewire::test(GetStarted::class)->call('continueToInstallationTest')->assertForbidden();
        $package->update(['sync_error' => 'GitHub refused access']);
        Livewire::test(GetStarted::class)->call('continueToInstallationTest')->assertForbidden();
    }

    public function test_all_failed_syncs_stay_with_guidance_and_check_again(): void
    {
        $package = $this->syncingPackage();
        $package->update(['sync_error' => 'GitHub refused access']);
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 3)
            ->assertSeeText('Every sync has finished, but no package is ready to install')
            ->assertSeeText('Check again')->call('recheckSync')->assertNoRedirect();
    }

    public function test_a_finished_batch_without_versions_is_failed_instead_of_waiting_forever(): void
    {
        $package = $this->syncingPackage();
        $this->syncBatch($package, ['pending_jobs' => 2, 'failed_jobs' => 2]);
        Livewire::test(GetStarted::class)->assertSeeText('Failed: No versions are ready to install yet.')
            ->assertDontSeeHtml('data-role="sync-spinner"')->call('checkSync')->assertNoRedirect();
    }

    public function test_a_failure_without_its_reason_keeps_polling_until_the_reason_arrives(): void
    {
        $package = $this->syncingPackage();
        session()->put(GetStarted::PACKAGES_KEY, [$package->id]);
        $this->syncBatch($package, ['pending_jobs' => 0, 'finished_at' => now()->timestamp]);

        $page = Livewire::test(GetStarted::class)
            ->assertSeeText('Failed: No versions are ready to install yet.')
            ->assertSeeHtml('wire:poll.3s.visible="checkSync"');

        $package->forceFill(['sync_error' => 'No installable versions found: no tag or branch contains a composer.json with a "name".'])->save();

        $page->call('checkSync')
            ->assertSeeText('Not a package')
            ->assertDontSeeHtml('wire:poll.3s.visible="checkSync"');
    }

    public function test_remove_deletes_only_the_failed_package_imported_by_this_wizard(): void
    {
        $source = Source::factory()->create();
        $ready = Package::factory()->for($source)->create();
        PackageVersion::factory()->for($ready)->create();
        $failed = Package::factory()->for($source)->create(['sync_error' => 'GitHub refused access']);
        $other = Package::factory()->for($source)->create(['sync_error' => 'GitHub refused access']);
        session()->put(GetStarted::PACKAGES_KEY, [$ready->id, $failed->id]);
        // Keep the mounted sync step while the ready package finishes.
        $this->syncBatch($ready);
        $page = Livewire::test(GetStarted::class)->assertSeeText('Remove')
            ->assertSeeHtml('wire:confirm="Remove '.e($failed->name).' from this registry?"');
        $page->call('removePackage', $failed->id)->assertNoRedirect();
        $this->assertModelMissing($failed);
        $this->assertModelExists($ready);
        $this->assertModelExists($other);
        $this->assertSame([$ready->id], session(GetStarted::PACKAGES_KEY));
        $page->call('removePackage', $other->id)->assertForbidden();
    }

    public function test_remove_refuses_a_ready_package_and_a_user_without_delete_permission(): void
    {
        $package = $this->syncingPackage();
        session()->put(GetStarted::PACKAGES_KEY, [$package->id]);
        $page = Livewire::test(GetStarted::class);
        PackageVersion::factory()->for($package)->create();
        $page->call('removePackage', $package->id)->assertForbidden();
        $package->versions()->delete();
        $package->update(['sync_error' => 'GitHub refused access']);
        $user = User::factory()->create();
        $user->givePermissionTo('ViewAny:Source');
        $this->actingAs($user);
        Livewire::test(GetStarted::class)->assertDontSeeText('Remove')
            ->call('removePackage', $package->id)->assertForbidden();
        $this->assertModelExists($package);
    }

    public function test_the_test_step_names_the_first_installable_package(): void
    {
        $source = Source::factory()->create();
        Package::factory()->for($source)->create(['sync_error' => 'GitHub refused access']);
        $ready = Package::factory()->for($source)->create(['name' => 'acme/widgets']);
        PackageVersion::factory()->for($ready)->create();
        Livewire::test(GetStarted::class)->call('continueToInstallationTest')->assertRedirect(GetStarted::getUrl());
        Livewire::test(GetStarted::class)->assertSet('mountedStep', 4)
            ->assertSeeText("Testing acme/widgets, the first package with a version. One package is enough: the test checks the registry's sign-in, package details and downloads.", false);
    }

    public function test_finish_lists_each_imported_package_with_its_status_and_link(): void
    {
        $source = Source::factory()->create();
        $ready = Package::factory()->for($source)->create(['name' => 'acme/widgets']);
        PackageVersion::factory()->for($ready)->create();
        $docs = Package::factory()->for($source)->create(['name' => 'acme/docs', 'sync_error' => 'No installable versions found: no tag or branch contains a composer.json with a "name".']);
        $failed = Package::factory()->for($source)->create(['name' => 'acme/failed', 'sync_error' => 'GitHub refused access']);
        $other = Package::factory()->for($source)->create(['name' => 'acme/unrelated']);
        session()->put(GetStarted::PACKAGES_KEY, [$ready->id, $docs->id, $failed->id]);
        Livewire::test(GetStarted::class)->call('continueToInstallationTest')->assertRedirect(GetStarted::getUrl());
        $page = Livewire::test(GetStarted::class)->set('passed', true)->assertSeeText('Your registry is ready');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//ul[@aria-label="Imported packages"]/li');
        $this->assertCount(3, $rows);
        foreach ([$ready, $docs, $failed] as $index => $package) {
            $this->assertStringContainsString($package->name.' → '.['ready', 'not a package', 'failed'][$index], $rows->item($index)->textContent);
            $link = $rows->item($index)->firstChild;
            $this->assertInstanceOf(\DOMElement::class, $link);
            $this->assertSame(GetStarted::packageUrl($package), $link->getAttribute('href'));
        }
        $page->assertDontSeeText($other->name);
    }

    public function test_the_navigation_offers_it_until_the_registry_has_installed_a_package(): void
    {
        $this->assertTrue(GetStarted::shouldRegisterNavigation());
        $this->assertSame('1/4', GetStarted::getNavigationBadge());

        Source::factory()->create();
        $this->assertSame('2/4', GetStarted::getNavigationBadge());

        $package = Package::factory()->create();
        Download::query()->create(['package_id' => $package->id, 'version' => '1.0.0', 'created_at' => now()]);

        $this->assertFalse(GetStarted::shouldRegisterNavigation());
    }

    public function test_the_navigation_leaves_it_out_for_a_user_who_cannot_see_sources(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(GetStarted::shouldRegisterNavigation());
    }

    public function test_the_wizards_import_has_no_access_step(): void
    {
        $this->appConfigured();
        Http::fake(['*' => Http::response(['token' => 'ghs_installation', 'expires_at' => now()->addHour()->toIso8601String(), 'repositories' => [], 'total_count' => 0])]);

        Source::factory()->create([
            'installation_id' => 42,
            'account' => 'acme',
            'metadata' => ['repository_selection' => 'selected', 'html_url' => 'https://github.com/organizations/acme/settings/installations/42'],
        ]);

        Livewire::test(GetStarted::class)
            ->assertSeeText('Automatic sync stays enabled')
            ->assertSchemaComponentHidden('create_webhook', 'importForm')
            ->assertSet('importData.create_webhook', true)
            ->assertDontSeeHtml('wire:poll')
            ->assertActionDoesNotExist(TestAction::make('importFromSource')->schemaComponent('onboarding.import.actions'));
    }
}

class RacingGetStarted extends GetStarted
{
    public function checkSync(): void
    {
        parent::checkSync();
        $package = Package::query()->first();
        if (! $package->versions()->exists()) {
            PackageVersion::factory()->for($package)->create();
        }
    }
}
