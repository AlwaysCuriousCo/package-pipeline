<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Enums\TokenAbility;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The npm registry surface: `npm publish` PUTs a document, the packument and
 * tarball endpoints serve it back, and the Composer surface never sees any
 * of it.
 */
class NpmRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
    }

    private function writeToken(): string
    {
        return Token::issue(
            User::factory()->superAdmin()->create(),
            'ci',
            [TokenAbility::RepositoryRead, TokenAbility::RepositoryWrite],
        )->plainText;
    }

    /**
     * The document `npm publish` PUTs: one version manifest and the tarball
     * as a base64 attachment.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function publishDocument(string $name, string $version, array $manifest = [], string $tarball = 'tarball-bytes'): array
    {
        return [
            '_id' => $name,
            'name' => $name,
            'dist-tags' => ['latest' => $version],
            'versions' => [
                $version => [
                    'name' => $name,
                    'version' => $version,
                    '_npmUser' => ['name' => 'somebody', 'email' => 'somebody@example.com'],
                    ...$manifest,
                ],
            ],
            '_attachments' => [
                "{$name}-{$version}.tgz" => [
                    'content_type' => 'application/octet-stream',
                    'data' => base64_encode($tarball),
                    'length' => strlen($tarball),
                ],
            ],
        ];
    }

    public function test_publishing_requires_authentication_even_on_a_public_repository(): void
    {
        $this->putJson('/npm/widgets', $this->publishDocument('widgets', '1.0.0'))
            ->assertUnauthorized();
    }

    public function test_publishing_requires_the_write_ability(): void
    {
        $read = Token::issue(User::factory()->superAdmin()->create(), 'ro', [TokenAbility::RepositoryRead]);

        $this->withToken($read->plainText)
            ->putJson('/npm/widgets', $this->publishDocument('widgets', '1.0.0'))
            ->assertForbidden();
    }

    public function test_a_publish_creates_the_package_and_serves_it_back_to_npm(): void
    {
        $document = $this->publishDocument('widgets', '1.0.0', [
            'description' => 'Built by CI.',
            'dependencies' => ['left-pad' => '^1.3.0'],
            // The client's own dist claims must never be echoed back.
            'dist' => ['tarball' => 'https://evil.example/x.tgz', 'integrity' => 'sha512-forged'],
        ], 'real-tarball-bytes');

        $this->withToken($this->writeToken())
            ->putJson('/npm/widgets', $document)
            ->assertCreated()
            ->assertJson(['name' => 'widgets', 'version' => '1.0.0', 'shasum' => sha1('real-tarball-bytes')]);

        $package = Package::query()->where('name', 'widgets')->sole();

        $this->assertSame(Ecosystem::Npm, $package->ecosystem);
        $this->assertFalse($package->webhook_enabled);
        $this->assertSame('Built by CI.', $package->description);
        $this->assertSame('1.0.0', $package->latest_version);

        $version = $package->versions()->sole();

        $this->assertSame(sha1('real-tarball-bytes'), $version->shasum);
        $this->assertArrayNotHasKey('_npmUser', $version->metadata);
        $this->assertArrayNotHasKey('dist', $version->metadata);
        $this->assertSame('real-tarball-bytes', Storage::disk('s3')->get($version->archive_path));

        // Served end to end on the default public repository, anonymously.
        // The version keys hold dots, so they are read as arrays rather than
        // through assertJsonPath's dot notation.
        $packument = $this->getJson('/npm/widgets')->assertOk();

        $this->assertSame('1.0.0', $packument->json('dist-tags')['latest']);

        $manifest = $packument->json('versions')['1.0.0'];

        $this->assertSame('^1.3.0', $manifest['dependencies']['left-pad']);
        $this->assertSame(sha1('real-tarball-bytes'), $manifest['dist']['shasum']);
        $this->assertSame(
            'sha512-'.base64_encode(hash('sha512', 'real-tarball-bytes', true)),
            $manifest['dist']['integrity'],
        );

        $tarball = parse_url((string) $manifest['dist']['tarball'], PHP_URL_PATH);

        $this->assertSame('/npm/widgets/-/widgets-1.0.0.tgz', $tarball);
        $this->get((string) $tarball)->assertOk();
    }

    public function test_a_scoped_name_answers_with_its_slash_encoded_as_npm_sends_it(): void
    {
        $this->withToken($this->writeToken())
            ->putJson('/npm/@acme/ui', $this->publishDocument('@acme/ui', '2.0.0'))
            ->assertCreated();

        $packument = $this->getJson('/npm/@acme%2fui')
            ->assertOk()
            ->assertJsonPath('name', '@acme/ui');

        $this->assertStringContainsString(
            '/npm/@acme/ui/-/ui-2.0.0.tgz',
            $packument->json('versions')['2.0.0']['dist']['tarball'],
        );
    }

    public function test_the_latest_dist_tag_is_the_highest_stable_release(): void
    {
        $token = $this->writeToken();

        foreach (['1.1.0', '1.0.0'] as $version) {
            $this->withToken($token)
                ->putJson('/npm/widgets', $this->publishDocument('widgets', $version))
                ->assertCreated();
        }

        $this->assertSame('1.1.0', $this->getJson('/npm/widgets')->json('dist-tags')['latest']);
    }

    public function test_the_composer_surface_never_serves_an_npm_package(): void
    {
        $this->withToken($this->writeToken())
            ->putJson('/npm/@acme/ui', $this->publishDocument('@acme/ui', '1.0.0'))
            ->assertCreated();

        $this->getJson('/p2/@acme/ui.json')->assertNotFound();
        $this->getJson('/list.json')->assertExactJson(['packageNames' => []]);
    }

    public function test_a_private_repository_requires_a_token_to_read(): void
    {
        Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $token = $this->writeToken();

        $this->withToken($token)
            ->putJson('/r/internal/npm/widgets', $this->publishDocument('widgets', '1.0.0'))
            ->assertCreated();

        // withToken() is a default header, so it outlives the publish above.
        $this->flushHeaders();

        $this->getJson('/r/internal/npm/widgets')->assertUnauthorized();
        $this->get('/r/internal/npm/widgets/-/widgets-1.0.0.tgz')->assertUnauthorized();

        $this->withToken($token)->getJson('/r/internal/npm/widgets')->assertOk();
        $this->withToken($token)->get('/r/internal/npm/widgets/-/widgets-1.0.0.tgz')->assertOk();
    }

    public function test_a_publish_addressed_to_one_name_refuses_a_document_naming_another(): void
    {
        $this->withToken($this->writeToken())
            ->putJson('/npm/widgets', $this->publishDocument('gadgets', '1.0.0'))
            ->assertUnprocessable();
    }

    public function test_a_publish_with_no_readable_tarball_is_refused(): void
    {
        $document = $this->publishDocument('widgets', '1.0.0');
        $document['_attachments'] = [];

        $this->withToken($this->writeToken())
            ->putJson('/npm/widgets', $document)
            ->assertUnprocessable();

        $this->assertDatabaseMissing('packages', ['name' => 'widgets']);
    }
}
