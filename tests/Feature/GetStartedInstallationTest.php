<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GetStarted;
use App\Filament\Pages\GetStartedChecklist;
use App\Filament\Widgets\GettingStarted;
use App\Models\Activity;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Models\Token;
use App\Models\User;
use App\Services\InstallationTestStages;
use App\Support\NewToken;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class GetStartedInstallationTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'https://packages.acme.test';

    private Package $package;

    private ?string $registryFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ROOT, 'services.github.app.id' => null, 'services.github.app.private_key' => null]);
        $this->actingAs(User::factory()->superAdmin()->create());
        Repository::default()->forceFill(['public' => false])->save();
        $this->package = Package::factory()->create([
            'name' => 'acme/widgets', 'latest_version' => 'v1.2.0',
            'repository_id' => Repository::default()->id, 'source_id' => Source::factory()->create()->id,
        ]);
        $this->package->versions()->create([
            'version' => 'v1.2.0', 'reference' => str_repeat('a', 40), 'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.2.0'],
        ]);
    }

    private function zip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onboarding-test');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', '{"name":"acme/widgets"}');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function fakeRegistry(?string $failure = null): void
    {
        $this->registryFailure = $failure;
        $zip = $this->zip();
        $version = $this->package->versions()->first()->version;
        Http::fake(function (Request $request) use ($zip, $version) {
            $failure = $this->registryFailure;
            if (str_ends_with($request->url(), '/packages.json')) {
                if (! $request->hasHeader('Authorization')) {
                    return Http::response([], $failure === 'credentials' ? 200 : ($this->package->composerRepository->public ? 200 : 401));
                }

                return Http::response(['packages' => [], 'metadata-url' => '/p2/%package%.json']);
            }
            if (str_contains($request->url(), '/p2/')) {
                return $failure === 'details' ? Http::response([], 404) : Http::response([
                    'packages' => ['acme/widgets' => [[
                        'name' => 'acme/widgets', 'version' => $version,
                        'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/release.zip', 'shasum' => sha1($zip)],
                    ]]],
                ]);
            }

            return match ($failure) {
                'download' => Http::response([], 403),
                'verify' => Http::response('corrupt archive'),
                default => Http::response($zip),
            };
        });
    }

    /** @param Testable<GetStarted> $page */
    private function runPageTest(Testable $page, string $method = 'autoRunTest'): string
    {
        ob_start();
        ob_start();
        try {
            $page->call($method);
        } finally {
            $streamed = (string) ob_get_clean();
            $streamed = ob_get_clean().$streamed;
        }

        return $streamed;
    }

    public function test_arrival_starts_once_and_renders_finish_with_copyable_commands(): void
    {
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class)->assertSet('testStarted', false)
            ->assertSeeHtml('wire:init="autoRunTest"')
            ->assertSeeText('Testing your registry')
            ->assertSeeHtml('showDetails: false')
            ->assertSeeHtml('aria-expanded="false"')
            ->assertSeeHtml('x-cloak x-show="showDetails"')
            ->assertDontSee('Your registry is ready');
        $streamed = $this->runPageTest($page);
        $this->assertStringContainsString('"name":"installation-status"', $streamed);
        $this->assertStringContainsString('"name":"installation-test"', $streamed);
        $this->assertStringContainsString('Reading package details', $streamed);
        $this->assertStringContainsString('Verifying the download', $streamed);
        $page->assertSet('passed', true)->assertSet('testStarted', true)
            ->assertDontSeeHtml('wire:init="autoRunTest"')
            ->assertSeeText('Your registry is ready')
            ->assertSeeText('composer require acme/widgets:^1.2.0')
            ->assertSeeText($this->package->composerRepository->configureCommand())
            ->assertActionHasUrl(TestAction::make('finish')->schemaComponent('onboarding.test.actions'), Dashboard::getUrl())
            ->assertActionExists(TestAction::make('importFromSource')->schemaComponent('onboarding.test.actions'))
            ->assertSchemaComponentExists('install.install_repository', 'installInstructions', fn (TextEntry $entry): bool => $entry->isCopyable($entry->getState()))
            ->assertSchemaComponentExists('install.install_require', 'installInstructions', fn (TextEntry $entry): bool => $entry->isCopyable($entry->getState()));
        $page->call('$refresh');
        $this->runPageTest($page);
        Http::assertSentCount(4);
        $this->assertSame(1, Activity::query()->where('event', 'installation-test')->count());
        $this->assertSame(0, Token::withTrashed()->count());
        $this->assertSame('Complete', GetStarted::getNavigationBadge());
        $this->assertFalse(GettingStarted::canView());
        $this->assertFalse(GetStartedChecklist::shouldRegisterNavigation());
    }

    public function test_successful_onboarding_forgets_the_current_viewers_cached_totals(): void
    {
        $this->fakeRegistry();
        $key = 'widget:registry-totals:user:'.auth()->id();
        $otherKey = 'widget:registry-totals:user:'.User::factory()->create()->id;
        $stale = [['label' => 'Packages', 'value' => '0', 'tone' => 'info']];
        Cache::put($key, $stale, 60);
        Cache::put($otherKey, $stale, 60);
        $this->assertTrue(Cache::has($key));

        $page = Livewire::test(GetStarted::class);
        $this->runPageTest($page);

        $page->assertSet('passed', true);
        $this->assertFalse(Cache::has($key));
        $this->assertSame($stale, Cache::get($otherKey));
    }

    public function test_finished_commands_reuse_the_package_read_token_action(): void
    {
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class);
        $this->runPageTest($page);
        $page->assertSeeText('your project needs a token: a password that lets it read packages');
        $page->callAction(TestAction::make('generateToken')->schemaComponent('install', 'installInstructions'), data: ['name' => 'My project', 'expires_at' => null])
            ->assertHasNoActionErrors();
        $plain = $page->get('plainTextToken');
        $this->assertIsString($plain);
        $token = Token::findByPlainText($plain);
        $this->assertNotNull($token);
        $this->assertFalse($token->installation_test);
        $this->assertSame([TokenAbility::RepositoryRead->value], $token->abilities);
        $this->assertStringContainsString(NewToken::composerCommandFor($token->composerUsername(), $plain, host: 'packages.acme.test'), html_entity_decode(strip_tags($page->html())));
        $expected = NewToken::composerCommandFor($token->composerUsername(), $plain, host: 'packages.acme.test');
        $page->assertSchemaComponentExists('install.install_auth', 'installInstructions', fn (TextEntry $entry): bool => $entry->getCopyableState($entry->getState()) === $expected);
    }

    public function test_alpha_only_finish_commands_opt_into_pre_release_stability(): void
    {
        $this->package->forceFill(['latest_version' => null])->save();
        $this->package->versions()->first()->update(['version' => 'v0.1.0-alpha.11']);
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class);
        $this->runPageTest($page);
        $page->assertSet('passed', true)->assertSeeText('composer require acme/widgets:^0.1@alpha')
            ->assertSeeText('Only pre-release versions are available');
    }

    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        return [
            'credentials' => ['credentials', 'Checking that the registry asks for credentials'],
            'details' => ['details', 'Reading package details'],
            'download' => ['download', 'Downloading the package'],
            'verify' => ['verify', 'Verifying the download'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failure_names_the_stage_and_can_be_run_again(string $failure, string $label): void
    {
        $this->fakeRegistry($failure);
        $page = Livewire::test(GetStarted::class);
        $this->runPageTest($page);
        $page->assertSet('passed', false)->assertSeeText('Could not complete: '.$label)
            ->assertSeeText('Run again')->assertDontSee('Your registry is ready');
        $this->assertSame(0, Token::withTrashed()->count());
        $this->assertFalse(GetStarted::complete());
        $this->fakeRegistry();
        $this->runPageTest($page, 'retryTest');
        $page->assertSet('passed', true)->assertSeeText('Your registry is ready');
        $this->assertSame(0, Token::withTrashed()->count());
    }

    public function test_the_public_registry_stage_does_not_claim_credentials_are_required(): void
    {
        $this->package->composerRepository->forceFill(['public' => true])->save();
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class);
        $this->runPageTest($page);
        $page->assertSet('passed', true)->assertSeeText('Checking access to the public registry')
            ->assertDontSee('Generate token');
    }

    public function test_a_new_visit_runs_again_even_after_a_historical_pass(): void
    {
        $this->fakeRegistry();
        $this->runPageTest(Livewire::test(GetStarted::class));
        $page = Livewire::test(GetStarted::class)->assertSet('passed', null)->assertSeeHtml('wire:init="autoRunTest"');
        $this->runPageTest($page);
        Http::assertSentCount(8);
    }

    public function test_auto_run_does_not_overlap_a_running_visit(): void
    {
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class);
        $key = 'onboarding-test:'.auth()->id().':'.$page->get('testVisit').':running';
        $lock = Cache::lock($key, 60);
        $this->assertTrue($lock->get());
        $this->runPageTest($page);
        Http::assertNothingSent();
        $lock->release();
        $this->runPageTest($page);
        Http::assertSentCount(4);
    }

    public function test_a_late_request_with_a_stale_snapshot_does_not_start_the_visit_again(): void
    {
        $this->fakeRegistry();
        $page = Livewire::test(GetStarted::class);
        // Another request for this visit already started the test; this
        // snapshot still says testStarted = false.
        Cache::add('onboarding-test:'.auth()->id().':'.$page->get('testVisit').':started', true, now()->addHour());
        $this->runPageTest($page);
        $page->assertSet('testStarted', false)->assertSet('passed', null);
        Http::assertNothingSent();
        $this->assertSame(0, Token::withTrashed()->count());
    }

    public function test_other_mounted_steps_have_no_auto_run_and_never_test_the_registry(): void
    {
        $this->package->versions()->delete();
        $page = Livewire::test(GetStarted::class)->assertSet('mountedStep', 3)->assertDontSeeHtml('wire:init="autoRunTest"');
        $this->runPageTest($page);
        Source::query()->update(['connected_at' => null]);
        $page = Livewire::test(GetStarted::class)->assertSet('mountedStep', 1)->assertDontSeeHtml('wire:init="autoRunTest"');
        $this->runPageTest($page);
        $this->package->delete();
        Source::query()->update(['connected_at' => now()]);
        $page = Livewire::test(GetStarted::class)->assertSet('mountedStep', 2)->assertDontSeeHtml('wire:init="autoRunTest"');
        $this->runPageTest($page);
        Http::assertNothingSent();
        $this->assertSame(0, Token::withTrashed()->count());
    }

    public function test_transcript_markers_show_each_stage_running_and_leave_later_stages_pending(): void
    {
        $lines = [];
        $markers = ['Checking registry without credentials', 'Reading package details', 'Downloading the package', 'Verifying the download'];
        foreach ($markers as $index => $marker) {
            $lines[] = ['text' => $marker, 'tone' => 'request'];
            $status = InstallationTestStages::fromTranscript($lines);
            foreach ($status['stages'] as $stageIndex => $stage) {
                $this->assertSame($stageIndex < $index ? 'passed' : ($stageIndex === $index ? 'running' : 'pending'), $stage['state']);
            }
        }
        $lines[] = ['text' => 'Installation test passed', 'tone' => 'done'];
        $this->assertTrue(InstallationTestStages::fromTranscript($lines)['passed']);
    }
}
