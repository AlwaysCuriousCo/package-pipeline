<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Source;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A throwaway RSA key, so the JWT signing path runs for real rather than
     * being mocked around.
     */
    private static ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$privateKey ??= $this->generatePrivateKey();

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => self::$privateKey,
            'api_url' => 'https://api.github.com',
        ]);

        config()->set('services.github.token', null);

        // A request this suite forgot to fake must fail, not reach GitHub.
        Http::preventStrayRequests();
    }

    private function generatePrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private function fakeInstallationToken(string $token = 'ghs_installation'): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => $token,
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
        ]);
    }

    public function test_an_installed_source_mints_and_caches_an_installation_token(): void
    {
        $this->fakeInstallationToken();

        $source = Source::factory()->create(['installation_id' => 42]);

        $this->assertSame('ghs_installation', $source->accessToken());
        $this->assertSame('ghs_installation', $source->accessToken());

        // The second call came from the cache, so GitHub was only asked once.
        Http::assertSentCount(1);
    }

    public function test_the_cached_installation_token_is_not_stored_in_the_clear(): void
    {
        $this->fakeInstallationToken();

        $source = Source::factory()->create(['installation_id' => 42]);
        $source->accessToken();

        $cached = Cache::get('github-app.installation-token.42');

        $this->assertNotNull($cached);
        $this->assertStringNotContainsString('ghs_installation', $cached);
    }

    public function test_the_app_jwt_is_signed_with_the_configured_key(): void
    {
        $this->fakeInstallationToken();

        Source::factory()->create(['installation_id' => 42])->accessToken();

        Http::assertSent(function ($request): bool {
            [$header, $payload, $signature] = explode('.', str_replace('Bearer ', '', $request->header('Authorization')[0]));

            $claims = json_decode($this->base64UrlDecode($payload), true);

            $this->assertSame('123456', $claims['iss']);
            $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);

            $verified = openssl_verify(
                "{$header}.{$payload}",
                $this->base64UrlDecode($signature),
                openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['key']),
                OPENSSL_ALGO_SHA256,
            );

            return $verified === 1;
        });
    }

    public function test_a_source_without_an_app_falls_back_to_its_own_token(): void
    {
        $source = Source::factory()->withToken('github_pat_abc')->create();

        $this->assertSame('github_pat_abc', $source->accessToken());
        $this->assertFalse($source->usesInstallation());
    }

    public function test_a_missing_app_configuration_is_reported_clearly(): void
    {
        config()->set('services.github.app.private_key', null);

        $source = Source::factory()->create(['installation_id' => 42]);

        $this->expectExceptionMessage('No GitHub App is configured');

        $source->accessToken();
    }

    public function test_a_private_key_can_be_given_as_a_file_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gh-key-');
        file_put_contents($path, self::$privateKey);

        config()->set('services.github.app.private_key', $path);

        $this->fakeInstallationToken();

        try {
            $this->assertSame(
                'ghs_installation',
                Source::factory()->create(['installation_id' => 42])->accessToken(),
            );
        } finally {
            unlink($path);
        }
    }

    public function test_a_package_authenticates_through_its_source(): void
    {
        $this->fakeInstallationToken();

        $source = Source::factory()->create(['account' => 'acme', 'installation_id' => 42]);
        $package = Package::factory()->create([
            'source_id' => $source->id,
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_package_level',
        ]);

        // The source wins over the package's own token.
        $this->assertSame('ghs_installation', $package->accessToken());
    }

    public function test_a_package_without_a_source_uses_its_own_token_then_the_env_fallback(): void
    {
        $package = Package::factory()->create([
            'repository' => 'https://gitlab.com/acme/widgets',
            'token' => 'ghp_package_level',
        ]);

        $this->assertNull($package->source_id);
        $this->assertSame('ghp_package_level', $package->accessToken());

        config()->set('services.github.token', 'ghp_env');
        $package->forceFill(['token' => null])->save();

        $this->assertSame('ghp_env', $package->fresh()->accessToken());
    }

    public function test_a_new_package_is_linked_to_the_source_owning_its_repository(): void
    {
        $source = Source::factory()->create(['account' => 'Acme']);

        $package = Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);

        // Matched case insensitively, as GitHub logins are.
        $this->assertSame($source->id, $package->source_id);
    }

    public function test_a_package_under_an_unconnected_owner_stays_unlinked(): void
    {
        Source::factory()->create(['account' => 'acme']);

        $package = Package::factory()->create(['repository' => 'https://github.com/other/widgets']);

        $this->assertNull($package->source_id);
    }

    public function test_a_source_chosen_by_hand_is_not_overwritten(): void
    {
        $owner = Source::factory()->create(['account' => 'acme']);
        $chosen = Source::factory()->create(['account' => 'mirror']);

        $package = Package::factory()->create([
            'source_id' => $chosen->id,
            'repository' => 'https://github.com/acme/widgets',
        ]);

        $this->assertSame($chosen->id, $package->source_id);
        $this->assertNotSame($owner->id, $package->source_id);
    }

    public function test_clearing_a_source_by_hand_survives_a_later_save(): void
    {
        Source::factory()->create(['account' => 'acme']);

        $package = Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);
        $this->assertNotNull($package->source_id);

        $package->forceFill(['source_id' => null])->save();
        $package->update(['description' => 'Still unlinked.']);

        $this->assertNull($package->fresh()->source_id);
    }

    public function test_an_unparseable_repository_does_not_break_saving(): void
    {
        Source::factory()->create(['account' => 'acme']);

        $package = Package::factory()->create(['repository' => 'not-a-repository-url']);

        $this->assertNull($package->source_id);
    }

    public function test_deleting_a_source_leaves_its_packages_in_place(): void
    {
        $source = Source::factory()->create(['account' => 'acme']);
        $package = Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);

        $source->delete();

        $this->assertNull($package->fresh()->source_id);
        $this->assertNotNull($package->fresh());
    }

    public function test_syncing_uses_the_source_credential_and_base_url(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'github.acme.test/api/v3/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
            ]),
            'github.acme.test/api/v3/repos/acme/widgets/branches*' => Http::response([]),
            'github.acme.test/api/v3/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'type' => 'library',
            ]),
        ]);

        $source = Source::factory()->create([
            'account' => 'acme',
            'installation_id' => 42,
            'base_url' => 'https://github.acme.test/api/v3',
        ]);

        $package = Package::factory()->unreleased()->create([
            'repository' => 'https://github.com/acme/widgets',
        ]);

        $this->assertSame($source->id, $package->source_id);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame('v1.0.0', $package->fresh()->latest_version);

        // Every repository call went to the source's own host, carrying the
        // installation token rather than any package-level credential.
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://github.acme.test/api/v3/repos/')
            && $request->header('Authorization') === ['Bearer ghs_installation']);
    }

    public function test_verify_records_the_reachable_repository_count(): void
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'api.github.com/app/installations/42' => Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
                'repository_selection' => 'selected',
            ]),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 7]),
        ]);

        $source = Source::factory()->disconnected()->create(['account' => null]);
        $source->forceFill(['installation_id' => 42])->save();

        $this->assertSame(7, $source->verify());

        $source->refresh();

        $this->assertSame('acme', $source->account);
        $this->assertSame('Organization', $source->account_type);
        $this->assertSame(7, $source->metadata['repository_count']);
        $this->assertNotNull($source->connected_at);
        $this->assertNull($source->connection_error);
    }

    public function test_a_failing_verify_records_the_reason_and_marks_the_source_disconnected(): void
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        $source = Source::factory()->create(['installation_id' => 42]);

        try {
            $source->verify();
            $this->fail('verify() should have thrown.');
        } catch (\Throwable) {
            // Expected — the recorded state is what matters.
        }

        $source->refresh();

        $this->assertNull($source->connected_at);
        $this->assertNotNull($source->connection_error);
        $this->assertFalse($source->isConnected());
    }

    public function test_verify_falls_back_to_the_user_endpoint_for_a_personal_account(): void
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/users/acme/repos*' => Http::response([
                ['full_name' => 'acme/widgets'],
                ['full_name' => 'acme/billing'],
            ]),
        ]);

        $source = Source::factory()->withToken()->create(['account' => 'acme']);

        $this->assertSame(2, $source->verify());
    }

    public function test_disconnecting_drops_the_credentials_but_keeps_the_packages(): void
    {
        $this->fakeInstallationToken();

        $source = Source::factory()->create(['account' => 'acme', 'installation_id' => 42]);
        $package = Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);
        $source->accessToken();

        $source->disconnect();

        $this->assertNull($source->installation_id);
        $this->assertNull($source->connected_at);
        $this->assertNull(Cache::get('github-app.installation-token.42'));
        $this->assertSame($source->id, $package->fresh()->source_id);
    }

    public function test_the_sync_command_can_be_narrowed_to_one_source(): void
    {
        Source::factory()->create(['name' => 'Acme', 'account' => 'acme']);
        Source::factory()->create(['name' => 'Other', 'account' => 'other']);

        Package::factory()->create(['name' => 'acme/widgets', 'repository' => 'https://github.com/acme/widgets']);
        Package::factory()->create(['name' => 'other/widgets', 'repository' => 'https://github.com/other/widgets']);

        // Both syncs fail against the un-faked API; what is asserted here is
        // which packages the filter selected, not the sync outcome.
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('packages:sync', ['--source' => 'acme'])
            ->expectsOutputToContain('acme/widgets')
            ->doesntExpectOutputToContain('other/widgets')
            ->assertFailed();
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
