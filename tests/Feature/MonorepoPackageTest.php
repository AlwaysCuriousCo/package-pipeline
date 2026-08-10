<?php

namespace Tests\Feature;

use App\Enums\WebhookCoverage;
use App\Models\Package;
use App\Models\Repository;
use App\Services\ArchiveSubtree;
use App\Services\PackageSynchronizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Several packages published from one repository.
 *
 * The interesting half is the archive: a provider only ever hands back the
 * whole repository, and a Composer dist for a subdirectory package has to be
 * that directory alone, re-rooted. Most of what follows is about proving the
 * bytes this registry stores are the right tree.
 *
 * @see docs/monorepos.md
 */
class MonorepoPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));
    }

    /**
     * A zipball shaped the way GitHub's is: everything under one directory
     * named for the repository and the commit, with two packages inside it and
     * files at the monorepo root that belong to neither.
     */
    private function monorepoZipball(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'monorepo-test-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addEmptyDir('acme-mono-a1b2c3d');
        $zip->addEmptyDir('acme-mono-a1b2c3d/packages');
        $zip->addEmptyDir('acme-mono-a1b2c3d/packages/widgets');

        // Repository-level files, which no package's dist may carry.
        $zip->addFromString('acme-mono-a1b2c3d/README.md', 'The Acme monorepo.');
        $zip->addFromString('acme-mono-a1b2c3d/composer.json', '{"name":"acme/mono"}');

        $zip->addFromString('acme-mono-a1b2c3d/packages/widgets/composer.json', '{"name":"acme/widgets"}');
        $zip->addFromString('acme-mono-a1b2c3d/packages/widgets/src/Widget.php', '<?php class Widget {}');
        // A path that repeats the subdirectory's own name, which a naive
        // string replacement rather than a prefix strip would mangle.
        $zip->addFromString('acme-mono-a1b2c3d/packages/widgets/src/widgets/Inner.php', '<?php class Inner {}');

        $zip->addFromString('acme-mono-a1b2c3d/packages/gadgets/composer.json', '{"name":"acme/gadgets"}');

        $zip->close();

        return $path;
    }

    /**
     * Fake the endpoints a sync of one package inside acme/mono reads.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGitHub(array $overrides = []): void
    {
        $zipball = file_get_contents($this->monorepoZipball());

        Http::fake($overrides + [
            'api.github.com/repos/acme/mono/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
            ]),
            'api.github.com/repos/acme/mono/branches*' => Http::response([]),
            'api.github.com/repos/acme/mono/contents/packages/widgets/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets, from the monorepo.',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/mono/contents/packages/gadgets/composer.json*' => Http::response([
                'name' => 'acme/gadgets',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/mono/contents/composer.json*' => Http::response(['name' => 'acme/mono']),
            'api.github.com/repos/acme/mono/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/mono/zipball/*' => fn () => Http::response($zipball, 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    private function makePackage(string $name, string $subdirectory, ?Repository $repository = null): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => $name,
            'repository' => 'https://github.com/acme/mono',
            'subdirectory' => $subdirectory,
            'repository_id' => ($repository ?? Repository::default())->id,
            'token' => 'ghp_secret',
        ]);
    }

    /**
     * @return array<string, string> entry name => contents
     */
    private function entriesOf(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The stored archive is not a readable zip.');

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $entries[$name] = (string) $zip->getFromIndex($index);
        }

        $zip->close();

        return $entries;
    }

    public function test_one_repository_url_can_serve_several_packages(): void
    {
        $this->makePackage('acme/widgets', 'packages/widgets');
        $this->makePackage('acme/gadgets', 'packages/gadgets');

        $this->assertSame(2, Package::query()->where('repository_path', 'acme/mono')->count());
    }

    /**
     * The widened unique index still claims a URL once per directory of it,
     * which is what stops the same package being added twice.
     */
    public function test_the_same_subdirectory_cannot_be_served_twice_from_one_repository(): void
    {
        $this->makePackage('acme/widgets', 'packages/widgets');

        $this->expectException(QueryException::class);

        $this->makePackage('acme/widgets-again', 'packages/widgets');
    }

    /**
     * The root is stored as '' rather than null precisely so that this still
     * collides — no engine considers two nulls equal, and a nullable column
     * would have quietly let the same repository be added twice.
     */
    public function test_the_repository_root_can_still_only_be_served_once(): void
    {
        $this->makePackage('acme/mono', '');

        $this->expectException(QueryException::class);

        $this->makePackage('acme/mono-again', '');
    }

    public function test_the_same_subdirectory_may_be_served_from_another_composer_repository(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal']);

        $this->makePackage('acme/widgets', 'packages/widgets');
        $second = $this->makePackage('acme/widgets', 'packages/widgets', $internal);

        $this->assertTrue($second->exists);
    }

    public function test_a_subdirectory_is_folded_to_one_spelling(): void
    {
        $package = $this->makePackage('acme/widgets', '/packages//widgets/');

        $this->assertSame('packages/widgets', $package->subdirectory);
        $this->assertTrue($package->hasSubdirectory());
    }

    public function test_a_subdirectory_that_climbs_out_of_the_repository_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makePackage('acme/widgets', '../../etc');
    }

    public function test_a_package_at_the_repository_root_has_no_subdirectory(): void
    {
        $package = $this->makePackage('acme/mono', '');

        $this->assertSame('', $package->subdirectory);
        $this->assertFalse($package->hasSubdirectory());
    }

    /**
     * Every package in a monorepo would otherwise be guessed "acme/mono", and
     * the second one refused by the (repository_id, name) unique index.
     */
    public function test_the_suggested_name_comes_from_the_subdirectory(): void
    {
        $package = new Package([
            'repository' => 'https://github.com/acme/mono',
            'subdirectory' => 'packages/widgets',
        ]);

        $this->assertSame('acme/widgets', $package->suggestedName());
    }

    public function test_the_manifest_is_read_from_the_subdirectory(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage('acme/placeholder', 'packages/widgets');

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame('acme/widgets', $package->refresh()->name);
        $this->assertSame('Widgets, from the monorepo.', $package->description);

        Http::assertSent(fn ($request): bool => str_contains(
            $request->url(),
            '/repos/acme/mono/contents/packages/widgets/composer.json',
        ));
    }

    /**
     * The point of the whole feature: what Composer downloads must be the
     * package's own tree at the root of the archive, and nothing else.
     */
    public function test_the_stored_dist_contains_only_the_subdirectory_re_rooted(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage('acme/widgets', 'packages/widgets');

        app(PackageSynchronizer::class)->sync($package);

        $version = $package->versions()->where('version', '1.0.0')->firstOrFail();

        $stored = tempnam(sys_get_temp_dir(), 'stored-dist-');
        file_put_contents($stored, Storage::disk(config('filesystems.dists'))->get($version->archive_path));

        $entries = $this->entriesOf($stored);

        // Exactly one top-level directory, which is what Composer strips.
        $roots = array_unique(array_map(fn (string $name): string => strtok($name, '/'), array_keys($entries)));
        $this->assertCount(1, $roots);

        $root = reset($roots);
        $paths = array_map(fn (string $name): string => substr($name, strlen($root) + 1), array_keys($entries));
        sort($paths);

        $this->assertSame([
            'composer.json',
            'src/Widget.php',
            'src/widgets/Inner.php',
        ], $paths);

        // The package's own manifest, not the monorepo's.
        $this->assertSame('{"name":"acme/widgets"}', $entries["{$root}/composer.json"]);

        // Nothing from outside the subtree survived.
        $this->assertStringNotContainsString('README.md', implode("\n", array_keys($entries)));
        $this->assertStringNotContainsString('gadgets', implode("\n", array_keys($entries)));
    }

    /**
     * Composer verifies a download against the shasum served beside it, so the
     * recorded hash has to be of the re-rooted archive rather than of the
     * whole-repository zipball it was cut from.
     */
    public function test_the_recorded_shasum_is_of_the_archive_actually_served(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage('acme/widgets', 'packages/widgets');

        app(PackageSynchronizer::class)->sync($package);

        $version = $package->versions()->where('version', '1.0.0')->firstOrFail();

        $stored = Storage::disk(config('filesystems.dists'))->get($version->archive_path);

        $this->assertSame(sha1($stored), $version->shasum);
    }

    /**
     * A package published from the root is untouched by any of this: its
     * archive is stored exactly as the provider sent it.
     */
    public function test_a_root_package_stores_the_providers_archive_unchanged(): void
    {
        $zipball = file_get_contents($this->monorepoZipball());

        $this->fakeGitHub();

        $package = $this->makePackage('acme/mono', '');

        app(PackageSynchronizer::class)->sync($package);

        $version = $package->versions()->where('version', '1.0.0')->firstOrFail();

        $this->assertSame(
            $zipball,
            Storage::disk(config('filesystems.dists'))->get($version->archive_path),
        );
    }

    /**
     * A dist holding the wrong tree is worse than a version that failed to
     * import, so an archive that does not contain the directory fails the ref
     * rather than storing whatever it did contain.
     */
    public function test_an_archive_without_the_subdirectory_fails_the_sync(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/mono/contents/packages/absent/composer.json*' => Http::response([
                'name' => 'acme/absent',
            ]),
        ]);

        $package = $this->makePackage('acme/absent', 'packages/absent');

        $this->expectException(RuntimeException::class);

        app(PackageSynchronizer::class)->sync($package);
    }

    /**
     * The re-rooted archive is stored and handed to Composer clients to
     * unpack, so an entry climbing out of the subtree is refused rather than
     * republished under a package's name. A git tree cannot hold one, which is
     * exactly why an archive that does is not to be trusted.
     */
    public function test_an_archive_entry_that_escapes_the_subdirectory_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hostile-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('acme-mono-a1b2c3d/packages/widgets/composer.json', '{"name":"acme/widgets"}');
        $zip->addFromString('acme-mono-a1b2c3d/packages/widgets/../../../escaped.php', '<?php // elsewhere');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes/');

        (new ArchiveSubtree)->reroot($path, 'packages/widgets', 'acme-widgets-1.0.0');
    }

    /**
     * A subdirectory the model refuses must never reach a provider's API, and
     * the field's validation rule is not the only thing between the two: the
     * panel reads a repository through an unsaved package, and what that
     * package carries must never be what normalization threw on — it would be
     * interpolated into an authenticated API path.
     */
    public function test_a_refused_subdirectory_is_never_handed_on(): void
    {
        $this->assertSame('', Package::storableSubdirectory('../../../etc'));
        $this->assertSame('', Package::storableSubdirectory('packages/../../escape'));
        $this->assertSame('', Package::storableSubdirectory(null));

        // And a merely untidy one is still folded rather than dropped.
        $this->assertSame('packages/widgets', Package::storableSubdirectory('/packages//widgets/'));
    }

    /**
     * A push says nothing about which package in a monorepo it touched, so
     * every one of them is owed the sync.
     */
    public function test_a_push_syncs_every_package_in_the_monorepo(): void
    {
        Queue::fake();

        config(['services.github.app.webhook_secret' => 'app-secret']);

        $widgets = $this->makePackage('acme/widgets', 'packages/widgets');
        $gadgets = $this->makePackage('acme/gadgets', 'packages/gadgets');

        $payload = ['ref' => 'refs/tags/v1.0.0', 'repository' => ['full_name' => 'acme/mono']];
        $body = json_encode($payload);

        $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ])->postJson(route('webhooks.github'), $payload)->assertAccepted();

        $this->assertNotNull($widgets->refresh()->webhook_received_at);
        $this->assertNotNull($gadgets->refresh()->webhook_received_at);
    }

    /**
     * Including a delivery that arrived on one package's own repository hook:
     * the hook belongs to a repository, not to the package it was created for.
     */
    public function test_a_delivery_on_one_packages_hook_syncs_its_siblings_too(): void
    {
        Queue::fake();

        $widgets = $this->makePackage('acme/widgets', 'packages/widgets');
        $gadgets = $this->makePackage('acme/gadgets', 'packages/gadgets');

        $widgets->forceFill(['webhook_id' => 42, 'webhook_secret' => 'hook-secret'])->save();

        $payload = ['ref' => 'refs/tags/v1.0.0', 'repository' => ['full_name' => 'acme/mono']];
        $body = json_encode($payload);

        $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        ])->postJson(route('webhooks.github.package', $widgets), $payload)->assertAccepted();

        $this->assertNotNull($gadgets->refresh()->webhook_received_at);
    }

    /**
     * Which is what makes one hook enough for the whole repository — worth
     * more than tidiness, since GitHub caps a repository at 20 of them.
     */
    public function test_a_sibling_hook_covers_a_package_that_has_none(): void
    {
        $widgets = $this->makePackage('acme/widgets', 'packages/widgets');
        $gadgets = $this->makePackage('acme/gadgets', 'packages/gadgets');

        $this->assertSame(WebhookCoverage::None, $gadgets->webhookCoverage());

        $widgets->forceFill(['webhook_id' => 42, 'webhook_secret' => 'hook-secret'])->save();

        $this->assertSame(WebhookCoverage::Sibling, $gadgets->refresh()->webhookCoverage());
        $this->assertTrue($gadgets->webhookCoverage()->isActive());
    }

    /**
     * `repository_path` discards the host, so packages on two providers can
     * share one — and a hook on one provider delivers nothing for the other.
     */
    public function test_a_hook_on_another_providers_repository_covers_nothing(): void
    {
        $onGitLab = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://gitlab.com/acme/mono',
            'subdirectory' => 'packages/widgets',
        ]);

        $onGitLab->forceFill(['webhook_id' => 42, 'webhook_secret' => 'hook-secret'])->save();

        $onGitHub = $this->makePackage('acme/gadgets', 'packages/gadgets');

        $this->assertSame(WebhookCoverage::None, $onGitHub->webhookCoverage());
    }
}
