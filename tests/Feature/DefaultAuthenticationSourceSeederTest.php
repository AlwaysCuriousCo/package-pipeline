<?php

namespace Tests\Feature;

use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultAuthenticationSourceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_sources_for_env_configured_providers(): void
    {
        config([
            'services.github.client_id' => 'gh-id',
            'services.github.client_secret' => 'gh-secret',
            'services.google.client_id' => 'goog-id',
            'services.google.client_secret' => 'goog-secret',
        ]);

        $this->seed(DatabaseSeeder::class);

        $github = AuthenticationSource::query()->where('name', 'GitHub')->firstOrFail();
        $this->assertSame(AuthProvider::Github, $github->provider);
        $this->assertSame('gh-secret', $github->client_secret);
        $this->assertTrue($github->active);

        $this->assertTrue(AuthenticationSource::query()->where('name', 'Google')->exists());
    }

    public function test_it_skips_providers_without_credentials_and_keeps_admin_edits(): void
    {
        config([
            'services.github.client_id' => 'gh-id',
            'services.github.client_secret' => 'gh-secret',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(AuthenticationSource::query()->where('name', 'Google')->exists());

        // An admin turns the seeded source off and narrows registration;
        // reseeding with a rotated secret updates only the credentials.
        AuthenticationSource::query()->where('name', 'GitHub')
            ->firstOrFail()
            ->update(['active' => false, 'allow_registration' => false]);

        config(['services.github.client_secret' => 'rotated']);

        $this->seed(DatabaseSeeder::class);

        $github = AuthenticationSource::query()->where('name', 'GitHub')->firstOrFail();
        $this->assertSame('rotated', $github->client_secret);
        $this->assertFalse($github->active);
        $this->assertFalse($github->allow_registration);
        $this->assertSame(1, AuthenticationSource::count());
    }
}
