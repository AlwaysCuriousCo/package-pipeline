<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\WordPressKind;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\RelationManagers\VersionsRelationManager;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * The WordPress surface: a Composer package marked as a plugin or theme gets
 * a second zip rooted at its slug, and the update-check, information and dist
 * endpoints a site's mu-plugin calls answer for it.
 *
 * @see docs/wordpress.md
 */
class WordPressRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN_FILE = 'acme-forms/acme-forms.php';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        // Private, so that every read below has to authenticate.
        Repository::default()->update(['public' => false]);
    }

    /**
     * A plugin's main file, with the header WordPress reads.
     */
    private function pluginFile(string $version): string
    {
        return "<?php\n/**\n * Plugin Name: Acme Forms\n * Plugin URI: https://acme.test/forms\n"
            ." * Description: Forms, by Acme.\n * Version: {$version}\n * Requires at least: 6.4\n"
            ." * Tested up to: 6.7\n * Requires PHP: 8.2\n * Author: Acme\n * Author URI: https://acme.test\n */\n";
    }

    /**
     * A zipball shaped the way GitHub's is: everything under one directory
     * named for the repository and the commit, the package files under
     * $directory inside it.
     *
     * @param  array<string, string>  $files  path inside the package => contents
     */
    private function zipball(array $files, string $directory = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wordpress-test-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('acme-forms-a1b2c3d/README.md', 'Repository readme.');

        foreach ($files as $name => $contents) {
            $zip->addFromString('acme-forms-a1b2c3d/'.($directory === '' ? '' : "{$directory}/").$name, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * Fake the endpoints a sync of acme/forms reads: one tag per entry in
     * $tags (tag => zipball bytes), each at its own commit.
     *
     * @param  array<string, string>  $tags
     */
    private function fakeGitHub(array $tags, string $composerName = 'acme/forms'): void
    {
        $shas = [];
        $fakes = [];

        foreach (array_keys($tags) as $index => $tag) {
            $shas[$tag] = str_repeat((string) ($index + 1), 40);
        }

        foreach ($tags as $tag => $zipball) {
            $fakes["api.github.com/repos/acme/forms/zipball/{$shas[$tag]}"] = fn () => Http::response($zipball, 200, [
                'Content-Type' => 'application/zip',
            ]);
        }

        Http::fake($fakes + [
            'api.github.com/repos/acme/forms/tags*' => Http::response(array_map(
                fn (string $tag): array => ['name' => $tag, 'commit' => ['sha' => $shas[$tag]]],
                array_keys($tags),
            )),
            'api.github.com/repos/acme/forms/branches*' => Http::response([]),
            'api.github.com/repos/acme/forms/contents/*composer.json*' => Http::response([
                'name' => $composerName,
                'type' => 'wordpress-plugin',
            ]),
            'api.github.com/repos/acme/forms/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-09-01T12:00:00Z']],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePackage(array $attributes = []): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/forms',
            'repository' => 'https://github.com/acme/forms',
            'repository_id' => Repository::default()->id,
            'token' => 'ghp_secret',
            'wordpress_kind' => WordPressKind::Plugin,
            'wordpress_slug' => 'acme-forms',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, string> entry name => contents
     */
    private function entriesOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wordpress-read-');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The stored archive is not a readable zip.');

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    private function readToken(): string
    {
        return Token::issue(User::factory()->superAdmin()->create(), 'site', [TokenAbility::RepositoryRead])->plainText;
    }

    /**
     * A plugin with releases 1.0.0 and 1.1.0, synced.
     */
    private function syncedPlugin(): Package
    {
        $this->fakeGitHub([
            'v1.0.0' => $this->zipball(['composer.json' => '{}', 'acme-forms.php' => $this->pluginFile('1.0.0')]),
            'v1.1.0' => $this->zipball([
                'composer.json' => '{}',
                'acme-forms.php' => $this->pluginFile('1.1.0'),
                'CHANGELOG.md' => "## 1.1.0\n\n- Added a **date** field.\n",
            ]),
        ]);

        $package = $this->makePackage();
        app(PackageSynchronizer::class)->sync($package);

        return $package->refresh();
    }

    /**
     * The `plugins` form field wp_update_plugins() posts.
     *
     * @param  array<string, string>  $installed  plugin file => installed version
     */
    private function pluginsField(array $installed): string
    {
        return (string) json_encode([
            'plugins' => array_map(fn (string $version): array => ['Name' => 'x', 'Version' => $version], $installed),
            'active' => array_keys($installed),
        ]);
    }

    public function test_the_wordpress_zip_is_rooted_at_the_slug_and_the_composer_dist_is_not(): void
    {
        $package = $this->syncedPlugin();

        $version = $package->versions()->where('version', '1.1.0')->sole();
        $disk = Storage::disk(config('filesystems.dists'));

        $bytes = (string) $disk->get($version->wordpress['path']);
        $entries = $this->entriesOf($bytes);

        $this->assertArrayHasKey(self::PLUGIN_FILE, $entries);
        $this->assertArrayHasKey('acme-forms/composer.json', $entries);
        foreach (array_keys($entries) as $name) {
            $this->assertStringStartsWith('acme-forms/', $name);
        }

        $this->assertSame(sha1($bytes), $version->wordpress['shasum']);
        $this->assertSame(strlen($bytes), $version->wordpress['size']);

        // The Composer archive is its own file, under the package's own name.
        $this->assertNotSame($version->archive_path, $version->wordpress['path']);
        $this->assertArrayHasKey('forms/acme-forms.php', $this->entriesOf((string) $disk->get($version->archive_path)));
    }

    public function test_a_monorepo_subdirectory_is_cut_out_and_rooted_at_the_slug(): void
    {
        $this->fakeGitHub([
            'v1.0.0' => $this->zipball([
                'composer.json' => '{}',
                'acme-forms.php' => $this->pluginFile('1.0.0'),
                'includes/Form.php' => '<?php class Form {}',
            ], 'plugins/forms'),
        ]);

        $package = $this->makePackage(['subdirectory' => 'plugins/forms']);
        app(PackageSynchronizer::class)->sync($package);

        $version = $package->versions()->sole();
        $entries = $this->entriesOf((string) Storage::disk(config('filesystems.dists'))->get($version->wordpress['path']));

        $this->assertEqualsCanonicalizing(
            ['acme-forms/composer.json', self::PLUGIN_FILE, 'acme-forms/includes/Form.php'],
            array_keys($entries),
        );
    }

    public function test_the_sync_reads_the_plugin_header(): void
    {
        $package = $this->syncedPlugin();

        $header = $package->versions()->where('version', '1.1.0')->sole()->wordpress;

        $this->assertSame('acme-forms.php', $header['file']);
        $this->assertSame('Acme Forms', $header['name']);
        $this->assertSame('1.1.0', $header['version']);
        $this->assertSame('6.4', $header['requires']);
        $this->assertSame('6.7', $header['tested']);
        $this->assertSame('8.2', $header['requires_php']);
        $this->assertNull($package->sync_error);
    }

    public function test_a_header_that_disagrees_with_its_tag_is_a_warning_not_a_failure(): void
    {
        $this->fakeGitHub([
            'v1.0.0' => $this->zipball(['composer.json' => '{}', 'acme-forms.php' => $this->pluginFile('1.0.0')]),
            'v1.1.0' => $this->zipball(['composer.json' => '{}', 'acme-forms.php' => $this->pluginFile('1.0.9')]),
        ]);

        $package = $this->makePackage();
        app(PackageSynchronizer::class)->sync($package);
        $package->refresh();

        $this->assertSame(2, $package->versions()->whereNotNull('wordpress')->count());
        $this->assertStringContainsString('1.1.0 (header: 1.0.9)', (string) $package->sync_error);
        $this->assertStringNotContainsString('1.0.0 (', (string) $package->sync_error);
    }

    public function test_marking_a_synced_package_as_a_plugin_backfills_without_moving_the_composer_archive(): void
    {
        $this->fakeGitHub([
            'v1.0.0' => $this->zipball(['composer.json' => '{}', 'acme-forms.php' => $this->pluginFile('1.0.0')]),
        ]);

        $package = $this->makePackage(['wordpress_kind' => null, 'wordpress_slug' => null]);
        app(PackageSynchronizer::class)->sync($package);

        $before = $package->versions()->sole();
        $this->assertNull($before->wordpress);

        $package->update(['wordpress_kind' => WordPressKind::Plugin, 'wordpress_slug' => 'acme-forms']);
        app(PackageSynchronizer::class)->sync($package->refresh());

        $after = $package->versions()->sole();

        $this->assertSame($before->archive_path, $after->archive_path);
        $this->assertSame($before->shasum, $after->shasum);
        $this->assertSame('acme-forms', $after->wordpress['slug']);
    }

    public function test_the_update_check_offers_only_a_newer_version(): void
    {
        $this->syncedPlugin();
        $token = $this->readToken();

        $response = $this->withToken($token)->post('/wp/plugins/update-check/1.1', [
            'plugins' => $this->pluginsField([self::PLUGIN_FILE => '1.0.0']),
        ])->assertOk();

        $update = $response->json('plugins')[self::PLUGIN_FILE];

        $this->assertSame('1.1.0', $update['new_version']);

        $this->assertSame('acme-forms', $update['slug']);
        $this->assertSame(self::PLUGIN_FILE, $update['plugin']);
        $this->assertSame('https://acme.test/forms', $update['url']);
        $this->assertSame(url('/wp/dist/acme-forms/1.1.0.zip'), $update['package']);
        $this->assertSame('6.4', $update['requires']);
        $this->assertSame('6.7', $update['tested']);
        $this->assertSame('8.2', $update['requires_php']);
        $this->assertSame([], (array) $response->json('no_update'));
    }

    public function test_a_current_plugin_is_no_update_and_an_unknown_one_is_omitted(): void
    {
        $this->syncedPlugin();

        $response = $this->withToken($this->readToken())->post('/wp/plugins/update-check/1.1', [
            'plugins' => $this->pluginsField([self::PLUGIN_FILE => '1.1.0', 'hello-dolly/hello.php' => '1.7.2']),
        ])->assertOk();

        $this->assertSame([], (array) $response->json('plugins'));
        $this->assertSame([self::PLUGIN_FILE], array_keys((array) $response->json('no_update')));
        $this->assertSame('1.1.0', $response->json('no_update')[self::PLUGIN_FILE]['new_version']);
    }

    public function test_the_update_check_accepts_a_json_body(): void
    {
        $this->syncedPlugin();

        $this->withToken($this->readToken())
            ->postJson('/wp/plugins/update-check/1.1', json_decode($this->pluginsField([self::PLUGIN_FILE => '1.0.0']), true))
            ->assertOk()
            ->assertJsonCount(1, 'plugins');
    }

    public function test_refusals_are_an_empty_401(): void
    {
        $this->syncedPlugin();

        $body = ['plugins' => $this->pluginsField([self::PLUGIN_FILE => '1.0.0'])];
        $apiOnly = Token::issue(User::factory()->superAdmin()->create(), 'api', [TokenAbility::ApiRead])->plainText;

        foreach ([null, 'not-a-token', $apiOnly] as $token) {
            $request = $token === null ? $this : $this->withToken($token);

            $response = $request->post('/wp/plugins/update-check/1.1', $body);

            $response->assertUnauthorized();
            $this->assertSame('{}', $response->getContent());

            $request->get('/wp/dist/acme-forms/1.1.0.zip')->assertUnauthorized();
        }

        // The Basic password, any username, works as the bearer does.
        $this->withHeader('Authorization', 'Basic '.base64_encode('site:'.$this->readToken()))
            ->post('/wp/plugins/update-check/1.1', $body)
            ->assertOk();
    }

    public function test_a_deploy_token_sees_only_what_it_was_granted(): void
    {
        $this->syncedPlugin();

        $other = Package::factory()->create(['repository_id' => Repository::default()->id]);
        $deploy = Token::issue(
            DeployToken::factory()->create(),
            'site',
            [TokenAbility::RepositoryRead],
        );
        $deploy->token->tokenable->packages()->attach($other);

        $this->withToken($deploy->plainText)->post('/wp/plugins/update-check/1.1', [
            'plugins' => $this->pluginsField([self::PLUGIN_FILE => '1.0.0']),
        ])->assertOk()->assertJsonCount(0, 'plugins');
    }

    public function test_plugin_information(): void
    {
        $this->syncedPlugin();

        $response = $this->withToken($this->readToken())
            ->getJson('/wp/plugins/info/1.2?action=plugin_information&request[slug]=acme-forms')
            ->assertOk()
            ->assertJson([
                'name' => 'Acme Forms',
                'slug' => 'acme-forms',
                'version' => '1.1.0',
                'author' => '<a href="https://acme.test">Acme</a>',
                'homepage' => 'https://acme.test/forms',
                'download_link' => url('/wp/dist/acme-forms/1.1.0.zip'),
                'requires' => '6.4',
                'tested' => '6.7',
                'requires_php' => '8.2',
                'sections' => ['description' => '<p>Forms, by Acme.</p>'],
            ]);

        $this->assertStringContainsString('<strong>date</strong>', $response->json('sections.changelog'));

        $this->withToken($this->readToken())
            ->getJson('/wp/plugins/info/1.2?action=plugin_information&request[slug]=hello-dolly')
            ->assertNotFound();
    }

    public function test_a_theme_round_trip(): void
    {
        $style = "/*\nTheme Name: Acme Studio\nVersion: 2.0.0\nRequires at least: 6.5\nRequires PHP: 8.1\nAuthor: Acme\n*/\n";

        $this->fakeGitHub([
            'v2.0.0' => $this->zipball(['composer.json' => '{}', 'style.css' => $style, 'index.php' => '<?php']),
        ], 'acme/studio');

        $package = $this->makePackage([
            'name' => 'acme/studio',
            'wordpress_kind' => WordPressKind::Theme,
            'wordpress_slug' => 'acme-studio',
        ]);
        app(PackageSynchronizer::class)->sync($package);

        $token = $this->readToken();

        $update = $this->withToken($token)->post('/wp/themes/update-check/1.1', [
            'themes' => json_encode(['active' => 'acme-studio', 'themes' => ['acme-studio' => ['Version' => '1.9.0']]]),
        ])->assertOk()->json('themes.acme-studio');

        $this->assertSame('acme-studio', $update['theme']);
        $this->assertSame('2.0.0', $update['new_version']);
        $this->assertSame('6.5', $update['requires']);
        $this->assertSame('8.1', $update['requires_php']);

        $this->withToken($token)
            ->getJson('/wp/themes/info/1.2?action=theme_information&request[slug]=acme-studio')
            ->assertOk()
            ->assertJsonPath('name', 'Acme Studio')
            ->assertJsonPath('download_link', $update['package']);

        // A theme is not a plugin: the plugin endpoints do not answer for it.
        $this->withToken($token)
            ->getJson('/wp/plugins/info/1.2?action=plugin_information&request[slug]=acme-studio')
            ->assertNotFound();

        $download = $this->withToken($token)->get($update['package'])->assertOk();

        $bytes = (string) $download->streamedContent();
        $this->assertSame($package->versions()->sole()->wordpress['shasum'], sha1($bytes));
        $this->assertArrayHasKey('acme-studio/style.css', $this->entriesOf($bytes));

        $this->assertSame(1, (int) PackageVersion::query()->sole()->total_downloads);
    }

    public function test_the_composer_dist_still_serves_a_wordpress_package(): void
    {
        $package = $this->syncedPlugin();
        $version = $package->versions()->where('version', '1.1.0')->sole();

        $response = $this->withToken($this->readToken())
            ->get("/dist/acme/forms/{$version->reference}.zip")
            ->assertOk();

        $this->assertSame($version->shasum, sha1((string) $response->streamedContent()));
    }

    public function test_archives_clean_keeps_the_wordpress_zips(): void
    {
        $package = $this->syncedPlugin();

        $this->travel(2)->hours();

        $this->artisan('archives:clean')->assertSuccessful();

        foreach ($package->versions()->get() as $version) {
            Storage::disk(config('filesystems.dists'))->assertExists($version->wordpress['path']);
        }
    }

    public function test_the_package_page_shows_the_slug_header_versions_and_dist_url(): void
    {
        $package = $this->syncedPlugin();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
            ->assertSee('acme-forms')
            ->assertSee(url('/wp/dist/acme-forms/1.1.0.zip'));

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewPackage::class])
            ->assertSee('Header version')
            ->assertSee('1.1.0');
    }
}
