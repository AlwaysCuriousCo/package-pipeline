<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@example.com';

    private function configure(array $overrides = []): void
    {
        config(['seeding.super_admin' => [
            'email' => self::EMAIL,
            'name' => 'Super Admin',
            'password' => 'correct-horse',
            ...$overrides,
        ]]);
    }

    public function test_it_seeds_the_super_admin_from_config(): void
    {
        $this->configure();

        $this->seed(UserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertSame('Super Admin', $user->name);
        $this->assertTrue(Hash::check('correct-horse', $user->password));
    }

    public function test_the_email_and_name_are_configurable(): void
    {
        $this->configure(['email' => 'someone.else@example.org', 'name' => 'Ops Team']);

        $this->seed(UserSeeder::class);

        $user = User::where('email', 'someone.else@example.org')->first();

        $this->assertNotNull($user);
        $this->assertSame('Ops Team', $user->name);
    }

    public function test_the_name_falls_back_to_a_generic_default(): void
    {
        // The default lives in config, so it must not be a real person's name.
        $this->assertSame('Super Admin', config('seeding.super_admin.name'));
    }

    public function test_no_super_admin_identity_is_hard_coded_in_source(): void
    {
        // The values come from the environment at runtime; what must never
        // appear is a committed address or password to fall back on.
        // example.com and friends are reserved for documentation (RFC 2606),
        // so they are placeholders rather than somebody's address.
        $realAddress = '/[A-Za-z0-9._%+-]+@(?!example\.(com|org|net)\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

        foreach (['database/seeders/UserSeeder.php', 'config/seeding.php', '.env.example'] as $path) {
            $this->assertDoesNotMatchRegularExpression(
                $realAddress,
                file_get_contents(base_path($path)),
                "{$path} contains a hard-coded email address.",
            );
        }

        // env() with no second argument means there is no committed default.
        $config = file_get_contents(base_path('config/seeding.php'));

        $this->assertStringContainsString("'email' => env('SUPER_ADMIN_EMAIL')", $config);
        $this->assertStringContainsString("'password' => env('SUPER_ADMIN_PASSWORD')", $config);
    }

    public function test_re_running_it_resets_the_password(): void
    {
        $this->configure(['password' => 'first-password']);
        $this->seed(UserSeeder::class);

        $this->configure(['password' => 'second-password']);
        $this->seed(UserSeeder::class);

        $user = User::where('email', self::EMAIL)->first();

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertTrue(Hash::check('second-password', $user->password));
    }

    /**
     * The seeder must not depend on env() being readable, because a cached
     * config stops .env from loading and makes env() return null.
     */
    public function test_it_seeds_when_the_environment_is_unreadable_but_config_is_set(): void
    {
        $original = $_ENV['SUPER_ADMIN_PASSWORD'] ?? null;
        unset($_ENV['SUPER_ADMIN_PASSWORD'], $_SERVER['SUPER_ADMIN_PASSWORD']);
        putenv('SUPER_ADMIN_PASSWORD');

        $this->configure(['password' => 'from-cached-config']);

        try {
            $this->seed(UserSeeder::class);

            $user = User::where('email', self::EMAIL)->first();

            $this->assertNotNull($user);
            $this->assertTrue(Hash::check('from-cached-config', $user->password));
        } finally {
            if ($original !== null) {
                $_ENV['SUPER_ADMIN_PASSWORD'] = $original;
            }
        }
    }

    public function test_it_fails_loudly_when_no_password_is_configured(): void
    {
        $this->configure(['password' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUPER_ADMIN_PASSWORD is not set');

        $this->seed(UserSeeder::class);
    }

    public function test_it_fails_loudly_when_no_email_is_configured(): void
    {
        $this->configure(['email' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUPER_ADMIN_EMAIL is not set');

        $this->seed(UserSeeder::class);
    }

    public function test_it_creates_no_user_when_the_configuration_is_incomplete(): void
    {
        $this->configure(['email' => null]);

        try {
            $this->seed(UserSeeder::class);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, User::count());
    }
}
