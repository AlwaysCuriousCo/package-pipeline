<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Models\Activity;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\InstallationTest;
use Composer\Semver\Semver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Installing a package the way Composer would, through the registry's own
 * public address — faked here, request by request.
 */
class InstallationTestTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'https://packages.acme.test';

    private User $user;

    private Package $package;

    private string $zip;

    private ?string $issuedPassword = null;

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::ROOT]);

        $this->user = User::factory()->superAdmin()->create();
        $this->actingAs($this->user);

        Repository::default()->forceFill(['public' => false])->save();

        $this->package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => Repository::default()->id]);
        $this->package->versions()->create([
            'version' => 'v1.2.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.2.0'],
        ]);

        $this->zip = $this->zipBytes();
    }

    private function zipBytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', '{"name":"acme/widgets"}');
        $zip->addFromString('src/Widget.php', '<?php');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * GitHub-free, registry-shaped answers: packages.json refuses anonymous
     * callers, and /p2 and /dist answer as the controller would.
     *
     * @param  array<string, mixed>  $overrides  url fragment => response
     */
    private function fakeRegistry(array $overrides = []): void
    {
        $shasum = sha1($this->zip);

        Http::fake(function (Request $request) use ($overrides, $shasum) {
            if ($request->hasHeader('Authorization')) {
                $credentials = base64_decode(substr($request->header('Authorization')[0], 6), true);
                $this->assertIsString($credentials);
                [$username, $password] = explode(':', $credentials, 2);
                $token = Token::query()->sole();
                $this->assertSame($token->composerUsername(), $username);
                $this->assertTrue($token->is(Token::findByPlainText($password)));
                $this->assertTrue($token->installation_test);
                $this->assertSame('Basic '.base64_encode($token->composerUsername().':'.$password), $request->header('Authorization')[0]);
                $this->issuedPassword = $password;
            }

            foreach ($overrides as $fragment => $response) {
                if (str_contains($request->url(), $fragment)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            $authenticated = $request->hasHeader('Authorization');

            return match (true) {
                str_ends_with($request->url(), '/packages.json') => $authenticated
                    ? Http::response(['packages' => [], 'metadata-url' => '/p2/%package%.json'])
                    : Http::response(['message' => 'Authentication required.'], 401),
                str_contains($request->url(), '/p2/acme/widgets.json') => Http::response([
                    'minified' => 'composer/2.0',
                    'packages' => ['acme/widgets' => [
                        ['name' => 'acme/widgets', 'version' => 'v1.3.0-beta1', 'version_normalized' => '1.3.0.0-beta1', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/beta.zip']],
                        ['version' => 'v1.2.0', 'version_normalized' => '1.2.0.0', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/'.str_repeat('a', 40).'.zip', 'shasum' => $shasum]],
                    ]],
                ]),
                str_contains($request->url(), '/dist/acme/widgets/') => Http::response($this->zip),
                default => Http::response('not found', 404),
            };
        });
    }

    private function install(?string $version = null): bool
    {
        return app(InstallationTest::class)->run($this->package, $this->user, $version, function (string $text, string $tone): void {
            $this->lines[] = [$text, $tone];
        });
    }

    private function transcript(): string
    {
        return implode("\n", array_column($this->lines, 0));
    }

    private function assertTokenGone(): void
    {
        $this->assertSame(0, Token::withTrashed()->count());
        $this->assertSame(0, Activity::query()->where('subject_type', (new Token)->getMorphClass())->count());
    }

    public function test_a_working_registry_passes_step_by_step(): void
    {
        $this->fakeRegistry();

        $this->assertTrue($this->install());

        $transcript = $this->transcript();

        $this->assertStringContainsString('composer config repositories.', $transcript);
        $this->assertStringContainsString('composer config http-basic.packages.acme.test', $transcript);
        $this->assertStringContainsString('401 Unauthorized', $transcript);
        $this->assertStringContainsString('credentials required ✓', $transcript);
        // The newest stable, as a bare composer require picks — not the beta.
        $this->assertStringContainsString('acme/widgets v1.2.0', $transcript);
        $this->assertStringContainsString('shasum matches · zip opens (2 files) ✓', $transcript);
        $this->assertStringContainsString('Installation test passed: acme/widgets v1.2.0 — metadata, authentication and archive checks passed at '.self::ROOT, $transcript);
        $this->assertSame('done', end($this->lines)[1]);

        // Requests use the issued token; the transcript keeps neither identity nor token suffix.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/packages.json')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Basic '));
        $this->assertStringContainsString('http-basic.packages.acme.test <composer-username> <temporary-token>', $transcript);
        $this->assertStringContainsString('Credentials redacted; test uses the actual issued credentials.', $transcript);
        $this->assertStringNotContainsString($this->user->email, $transcript);
        $this->assertNotNull($this->issuedPassword);
        $this->assertStringNotContainsString($this->issuedPassword, $transcript);
        $this->assertStringNotContainsString(substr($this->issuedPassword, -4), $transcript);
        $this->assertStringNotContainsString('pp_', $transcript);

        $this->assertTokenGone();
        $entry = Activity::query()->where('event', 'installation-test')->sole();
        $this->assertTrue($entry->properties['passed']);
        $this->assertTrue(InstallationTest::hasPassed());
    }

    public function test_a_requested_version_is_the_one_installed(): void
    {
        $this->fakeRegistry();

        $this->assertTrue($this->install('v1.3.0-beta1'));
        $this->assertStringContainsString('acme/widgets v1.3.0-beta1', $this->transcript());
    }

    public function test_a_package_with_only_branches_is_found_in_the_dev_metadata(): void
    {
        $this->fakeRegistry([
            '/p2/acme/widgets~dev.json' => Http::response(['minified' => 'composer/2.0', 'packages' => ['acme/widgets' => [
                ['name' => 'acme/widgets', 'version' => 'dev-main', 'version_normalized' => 'dev-main', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/'.str_repeat('b', 40).'.zip']],
            ]]]),
            '/p2/acme/widgets.json' => Http::response(['minified' => 'composer/2.0', 'packages' => ['acme/widgets' => []]]),
        ]);

        $this->assertTrue($this->install());

        $this->assertStringContainsString('/p2/acme/widgets~dev.json', $this->transcript());
        $this->assertStringContainsString('Picked acme/widgets dev-main — no tagged release yet', $this->transcript());
    }

    public function test_a_package_with_only_pre_releases_says_what_a_project_must_require(): void
    {
        $this->fakeRegistry([
            '/p2/acme/widgets.json' => Http::response(['minified' => 'composer/2.0', 'packages' => ['acme/widgets' => [
                ['name' => 'acme/widgets', 'version' => 'v0.1.0-alpha.11', 'version_normalized' => '0.1.0.0-alpha11', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/'.str_repeat('c', 40).'.zip']],
                ['version' => 'v0.1.0-alpha.10', 'version_normalized' => '0.1.0.0-alpha10', 'dist' => ['type' => 'zip', 'url' => self::ROOT.'/dist/acme/widgets/'.str_repeat('d', 40).'.zip']],
            ]]]),
        ]);

        $this->assertTrue($this->install());

        $this->assertStringContainsString('Picked acme/widgets v0.1.0-alpha.11 — no stable release yet, so a project needs `:^0.1@alpha` to require it', $this->transcript());
        $this->assertStringContainsString('composer require acme/widgets:^0.1@alpha', $this->transcript());
        $this->assertTrue(Semver::satisfies('0.1.0-alpha11', '^0.1@alpha'));
    }

    public function test_a_stable_pick_needs_no_constraint(): void
    {
        $this->fakeRegistry();

        $this->assertTrue($this->install());

        $this->assertStringNotContainsString('no stable release yet', $this->transcript());
        $this->assertStringNotContainsString('composer require acme/widgets:', $this->transcript());
    }

    public function test_a_private_repository_that_answers_anonymously_fails(): void
    {
        $this->fakeRegistry(['/packages.json' => fn (Request $request) => Http::response(['packages' => [], 'metadata-url' => '/p2/%package%.json'])]);

        $this->assertFalse($this->install());

        $this->assertStringContainsString('200 OK', $this->transcript());
        $this->assertStringContainsString('so anyone can read it', $this->transcript());
        $this->assertSame('reach', Activity::query()->where('event', 'installation-test')->sole()->properties['failed_step']);
        $this->assertTokenGone();
    }

    public function test_a_package_missing_from_the_metadata_fails_with_what_to_check(): void
    {
        $this->fakeRegistry(['/p2/acme/widgets.json' => Http::response(['message' => 'Not found'], 404)]);

        $this->assertFalse($this->install());

        $this->assertStringContainsString('404 Not Found', $this->transcript());
        $this->assertStringContainsString("acme/widgets is not in this repository's metadata", $this->transcript());
        $this->assertStringNotContainsString('Installation test passed', $this->transcript());
        $this->assertTokenGone();
    }

    public function test_an_archive_that_does_not_match_its_shasum_fails(): void
    {
        $this->fakeRegistry(['/dist/acme/widgets/' => Http::response($this->zipBytes().'tampered')]);

        $this->assertFalse($this->install());

        $this->assertStringContainsString('shasum mismatch: expected '.sha1($this->zip), $this->transcript());
        $this->assertStringContainsString('Composer would refuse it', $this->transcript());
        $this->assertTokenGone();
    }

    public function test_a_cloudflare_block_is_named_with_its_code(): void
    {
        $this->fakeRegistry(['/packages.json' => fn (Request $request) => $request->hasHeader('Authorization')
            ? Http::response('<html><title>Access denied | packages.acme.test used Cloudflare to restrict access</title><span class="cf-error-code">Error 1020</span></html>', 403, ['cf-ray' => '8c1f2e3d4a5b6c7d-LHR', 'server' => 'cloudflare'])
            : Http::response(['message' => 'Authentication required.'], 401)]);

        $this->assertFalse($this->install());

        $transcript = $this->transcript();

        $this->assertStringContainsString('403 Forbidden', $transcript);
        $this->assertStringContainsString('Response: Access denied', $transcript);
        $this->assertStringContainsString('Cloudflare error 1020 · ray 8c1f2e3d4a5b6c7d-LHR', $transcript);
        $this->assertStringContainsString('Hint: Cloudflare blocked the request (error 1020)', $transcript);
        $this->assertTokenGone();
    }

    public function test_the_token_is_deleted_even_when_the_address_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: packages.acme.test'));

        $this->assertFalse($this->install());

        $this->assertStringContainsString('Could not resolve host', $this->transcript());
        $this->assertStringContainsString('the address does not reach this registry', $this->transcript());
        $this->assertStringContainsString('<composer-username> <temporary-token>', $this->transcript());
        $this->assertStringNotContainsString($this->user->email, $this->transcript());
        $this->assertTokenGone();
    }

    public function test_the_package_page_streams_the_transcript_and_keeps_it(): void
    {
        $this->fakeRegistry();

        // Livewire flushes each streamed chunk out of the current buffer, so
        // a second one underneath is what catches them.
        ob_start();
        ob_start();

        try {
            $page = Livewire::test(ViewPackage::class, ['record' => $this->package->getRouteKey()])
                ->callAction('testInstallation', data: ['version' => null]);
        } finally {
            $streamed = (string) ob_get_clean();
            $streamed = ob_get_clean().$streamed;
        }

        // Each line went out as it happened, into the modal's terminal...
        $this->assertStringContainsString('"name":"installation-test"', $streamed);
        $this->assertStringContainsString('Downloading '.str_replace('/', '\/', self::ROOT), $streamed);
        $this->assertStringContainsString('Installation test passed', $streamed);

        // ...and stays on the component for the modal to render once the
        // request ends, which leaves the modal open on it.
        /** @var list<array{text: string, tone: string}> $kept */
        $kept = $page->get('installationTranscript');
        $this->assertStringContainsString('Installation test passed', end($kept)['text']);
        $page->assertActionMounted('testInstallation');
    }
}
