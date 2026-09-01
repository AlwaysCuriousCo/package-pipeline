<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Enums\TokenAbility;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Python index surface: `twine upload` POSTs distribution files, the PEP
 * 503 simple index serves them back to pip, and the other surfaces never see
 * any of it.
 */
class PypiRegistryTest extends TestCase
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
     * The form fields `twine upload` posts alongside the file.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function uploadFields(string $name, string $version, string $filename, string $bytes, array $extra = []): array
    {
        return [
            ':action' => 'file_upload',
            'protocol_version' => '1',
            'name' => $name,
            'version' => $version,
            'filetype' => str_ends_with($filename, '.whl') ? 'bdist_wheel' : 'sdist',
            'sha256_digest' => hash('sha256', $bytes),
            'content' => UploadedFile::fake()->createWithContent($filename, $bytes),
            ...$extra,
        ];
    }

    public function test_uploading_requires_authentication_even_on_a_public_repository(): void
    {
        $this->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertUnauthorized();
    }

    public function test_uploading_requires_the_write_ability(): void
    {
        $read = Token::issue(User::factory()->superAdmin()->create(), 'ro', [TokenAbility::RepositoryRead]);

        $this->withToken($read->plainText)
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertForbidden();
    }

    public function test_a_release_accumulates_its_files_and_serves_them_to_pip(): void
    {
        $token = $this->writeToken();

        $this->withToken($token)
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes', [
                'summary' => 'Built by CI.',
            ]))
            ->assertCreated();

        $this->withToken($token)
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0-py3-none-any.whl', 'wheel-bytes', [
                'requires_python' => '>=3.9',
            ]))
            ->assertCreated();

        $package = Package::query()->where('name', 'widgets')->sole();

        $this->assertSame(Ecosystem::Pypi, $package->ecosystem);
        $this->assertSame('Built by CI.', $package->description);
        $this->assertSame('1.0.0', $package->latest_version);

        // One version row, two files behind it — and both stored objects live.
        $version = $package->versions()->sole();
        $files = $version->metadata['files'];

        $this->assertCount(2, $files);

        foreach ($files as $file) {
            $this->assertTrue(Storage::disk('s3')->exists($file['path']));
        }

        // The project page carries an anchor per file, each with its sha256
        // and the wheel's requires-python, readable anonymously on the
        // default public repository.
        $page = $this->get('/pypi/simple/widgets/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString('widgets-1.0.0.tar.gz</a>', $page);
        $this->assertStringContainsString('#sha256='.hash('sha256', 'sdist-bytes'), $page);
        $this->assertStringContainsString('widgets-1.0.0-py3-none-any.whl</a>', $page);
        $this->assertStringContainsString('data-requires-python="&gt;=3.9"', $page);

        // And the files stream back byte for byte.
        $this->get('/pypi/files/widgets/1.0.0/widgets-1.0.0.tar.gz')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=widgets-1.0.0.tar.gz');
        $this->get('/pypi/files/widgets/1.0.0/widgets-1.0.0-py3-none-any.whl')->assertOk();
    }

    public function test_the_index_root_lists_served_projects(): void
    {
        $this->withToken($this->writeToken())
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertCreated();

        $this->flushHeaders();

        $this->assertStringContainsString(
            '/pypi/simple/widgets/',
            (string) $this->get('/pypi/simple/')->assertOk()->getContent(),
        );
    }

    public function test_names_are_normalized_as_pep_503_requires(): void
    {
        $this->withToken($this->writeToken())
            ->post('/pypi/legacy', $this->uploadFields('My__Widget.Kit', '1.0.0', 'my_widget_kit-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertCreated()
            ->assertJson(['name' => 'my-widget-kit']);

        // pip normalizes before asking, but a hand-typed spelling folds too.
        $this->get('/pypi/simple/My.Widget-Kit/')->assertOk();
    }

    public function test_a_corrupted_upload_is_refused_by_its_own_digest(): void
    {
        $fields = $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes');
        $fields['sha256_digest'] = hash('sha256', 'other-bytes');

        $this->withToken($this->writeToken())
            ->post('/pypi/legacy', $fields)
            ->assertUnprocessable();

        $this->assertDatabaseMissing('packages', ['name' => 'widgets']);
    }

    public function test_a_name_served_by_another_ecosystem_is_refused(): void
    {
        Package::factory()->create(['name' => 'widgets', 'ecosystem' => Ecosystem::Npm]);

        $this->withToken($this->writeToken())
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertStatus(409);
    }

    public function test_a_private_repository_requires_a_token_to_read(): void
    {
        Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $token = $this->writeToken();

        $this->withToken($token)
            ->post('/r/internal/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertCreated();

        $this->flushHeaders();

        $this->get('/r/internal/pypi/simple/widgets/')->assertUnauthorized();
        $this->get('/r/internal/pypi/files/widgets/1.0.0/widgets-1.0.0.tar.gz')->assertUnauthorized();

        $this->withToken($token)->get('/r/internal/pypi/simple/widgets/')->assertOk();
    }

    public function test_the_composer_and_npm_surfaces_never_serve_a_python_package(): void
    {
        $this->withToken($this->writeToken())
            ->post('/pypi/legacy', $this->uploadFields('widgets', '1.0.0', 'widgets-1.0.0.tar.gz', 'sdist-bytes'))
            ->assertCreated();

        $this->flushHeaders();

        $this->getJson('/npm/widgets')->assertNotFound();
        $this->getJson('/list.json')->assertExactJson(['packageNames' => []]);
    }
}
