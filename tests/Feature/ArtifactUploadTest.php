<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * POST /upload/{vendor}/{package}: CI publishing a built zip, no VCS source
 * behind it. The zip's composer.json is the metadata, the zip itself the
 * served archive.
 */
class ArtifactUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
    }

    /**
     * @param  array<string, mixed>  $composerJson
     */
    private function makeZip(array $composerJson, string $folder = 'build-output/'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString($folder.'composer.json', (string) json_encode($composerJson));
        $zip->addFromString($folder.'src/Widget.php', '<?php // built');
        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', test: true);
    }

    private function writeToken(): string
    {
        return Token::issue(
            User::factory()->superAdmin()->create(),
            'ci',
            [TokenAbility::RepositoryRead, TokenAbility::RepositoryWrite],
        )->plainText;
    }

    public function test_uploading_requires_authentication_even_on_a_public_repository(): void
    {
        $this->post('/upload/acme/widgets', ['file' => $this->makeZip(['name' => 'acme/widgets'])])
            ->assertUnauthorized();
    }

    public function test_uploading_requires_the_write_ability(): void
    {
        $read = Token::issue(User::factory()->superAdmin()->create(), 'ro', [TokenAbility::RepositoryRead]);

        $this->withToken($read->plainText)
            ->post('/upload/acme/widgets', ['file' => $this->makeZip(['name' => 'acme/widgets'])])
            ->assertForbidden();
    }

    public function test_an_upload_creates_the_package_its_version_and_the_served_archive(): void
    {
        $zip = $this->makeZip([
            'name' => 'acme/widgets',
            'description' => 'Built by CI.',
            'type' => 'library',
            'version' => '1.2.3',
            'require' => ['php' => '^8.3'],
            'scripts' => ['post-install-cmd' => 'echo done'],
            // Not part of the curated subset; must not be served.
            'repositories' => [['type' => 'vcs', 'url' => 'https://internal.example']],
        ]);

        $expectedShasum = sha1_file($zip->getRealPath());

        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', ['file' => $zip])
            ->assertCreated()
            ->assertJson(['name' => 'acme/widgets', 'version' => '1.2.3', 'shasum' => $expectedShasum]);

        $package = Package::query()->where('name', 'acme/widgets')->sole();

        $this->assertNull($package->repository);
        $this->assertFalse($package->webhook_enabled);
        $this->assertSame('Built by CI.', $package->description);
        $this->assertSame('1.2.3', $package->latest_version);
        $this->assertTrue($package->composerRepository->isDefault());

        $version = $package->versions()->sole();

        $this->assertSame('1.2.3', $version->version);
        $this->assertSame($expectedShasum, $version->shasum);
        $this->assertSame(['php' => '^8.3'], $version->metadata['require']);
        $this->assertArrayHasKey('scripts', $version->metadata);
        $this->assertArrayNotHasKey('repositories', $version->metadata);
        $this->assertTrue(Storage::disk('s3')->exists($version->archive_path));

        // Served end to end: metadata advertises it and the dist streams it.
        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', '1.2.3');

        $this->get("/dist/acme/widgets/{$version->reference}.zip")->assertOk();
    }

    public function test_an_explicit_version_field_beats_the_manifests(): void
    {
        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '0.9.0']),
                'version' => '1.0.0-rc.1',
            ])
            ->assertCreated()
            ->assertJsonPath('version', '1.0.0-rc.1');
    }

    public function test_a_version_must_come_from_somewhere(): void
    {
        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', ['file' => $this->makeZip(['name' => 'acme/widgets'])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);
    }

    public function test_reuploading_a_version_replaces_its_archive(): void
    {
        $token = $this->writeToken();

        $this->withToken($token)->post('/upload/acme/widgets', [
            'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '1.0.0']),
        ])->assertCreated();

        $first = Package::query()->where('name', 'acme/widgets')->sole()->versions()->sole();

        $this->withToken($token)->post('/upload/acme/widgets', [
            'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '1.0.0', 'description' => 'rebuilt']),
        ])->assertCreated();

        $versions = Package::query()->where('name', 'acme/widgets')->sole()->versions()->get();

        $this->assertCount(1, $versions);
        $this->assertNotSame($first->shasum, $versions->sole()->shasum);
    }

    public function test_a_zip_without_a_manifest_is_rejected(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('src/Widget.php', '<?php');
        $zip->close();

        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => new UploadedFile($path, 'package.zip', 'application/zip', test: true),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_a_manifest_naming_another_package_is_rejected(): void
    {
        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/gadgets', 'version' => '1.0.0']),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_an_archive_over_the_configured_ceiling_is_refused(): void
    {
        // One megabyte, so proving the rule exists does not cost a
        // hundred-megabyte upload.
        config(['registry.upload_max_megabytes' => 1]);

        $path = (string) tempnam(sys_get_temp_dir(), 'upload-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', (string) json_encode(['name' => 'acme/widgets', 'version' => '1.0.0']));
        // Random bytes: deflate would squeeze anything repetitive back under
        // the ceiling, and it is the stored size that is being bounded.
        $zip->addFromString('src/blob.bin', random_bytes(2 * 1024 * 1024));
        $zip->close();

        // Everything else about this archive is valid, so the 422 can only be
        // the size rule.
        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => new UploadedFile($path, 'package.zip', 'application/zip', test: true),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_a_manifest_that_declares_an_absurd_size_is_refused_without_being_decoded(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', str_repeat('a', 2 * 1024 * 1024));
        $zip->close();

        // The shape of the attack: a tiny archive whose one interesting entry
        // is not tiny at all.
        $this->assertLessThan(64 * 1024, (int) filesize($path));

        $response = $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => new UploadedFile($path, 'package.zip', 'application/zip', test: true),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        // The declared size is what refused it. The entry is not JSON either,
        // so a "not valid JSON" answer would mean it had been inflated first.
        $this->assertStringContainsString('refused unread', (string) $response->json('errors.file.0'));
    }

    public function test_something_that_is_not_a_zip_is_rejected(): void
    {
        $this->withToken($this->writeToken())
            ->post('/upload/acme/widgets', [
                'file' => UploadedFile::fake()->create('package.txt', 1, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_uploads_land_in_the_addressed_repository(): void
    {
        Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $this->withToken($this->writeToken())
            ->post('/r/internal/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '2.0.0']),
            ])
            ->assertCreated();

        $package = Package::query()->where('name', 'acme/widgets')->sole();

        $this->assertSame('internal', $package->composerRepository->path);
    }

    public function test_a_scoped_deploy_token_only_publishes_into_its_grants(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $deployToken = DeployToken::factory()->create();
        $deployToken->repositories()->attach($internal);

        $plain = Token::issue($deployToken, 'ci', [TokenAbility::RepositoryWrite])->plainText;

        // The granted repository accepts the publish...
        $this->withToken($plain)
            ->post('/r/internal/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '1.0.0']),
            ])
            ->assertCreated();

        // ...the (public!) default repository does not: readable is not
        // writable.
        $this->withToken($plain)
            ->post('/upload/acme/gadgets', [
                'file' => $this->makeZip(['name' => 'acme/gadgets', 'version' => '1.0.0']),
            ])
            ->assertForbidden();
    }
}
