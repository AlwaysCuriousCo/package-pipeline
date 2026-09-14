<?php

namespace Database\Seeders;

use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use App\Models\Repository;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The admin account is deliberately absent: it is created by
     * `php artisan admin:create`, which keeps the password out of the
     * environment and out of version control. What is seeded here is the
     * permission set that account's role is granted.
     */
    public function run(): void
    {
        $this->call(ShieldPermissionSeeder::class);

        // The repository served at the registry root. Created lazily wherever
        // it is first needed, but seeding it means a fresh install shows it in
        // the panel before any package or Composer request exists.
        Repository::default();

        // Out-of-the-box login providers: when OAuth credentials are in the
        // environment, seed the matching authentication source so the login
        // button appears without any panel setup. Only the credentials are
        // owned by the environment — everything an admin can edit on the
        // source (active, registration rules, domains, role) is left alone
        // on reseeds.
        foreach ([AuthProvider::Google, AuthProvider::Github] as $provider) {
            $clientId = config("services.{$provider->value}.client_id");
            $clientSecret = config("services.{$provider->value}.client_secret");

            if (blank($clientId) || blank($clientSecret)) {
                continue;
            }

            // Matched on the provider, not the name: the name is the login
            // button's label and an admin may rewrite it, and reseeding after
            // that would otherwise mint a second source for the same provider
            // — two buttons, and rotated credentials landing on neither of the
            // ones in use.
            $source = AuthenticationSource::query()->firstOrNew(['provider' => $provider]);

            $source->fill(['client_id' => $clientId, 'client_secret' => $clientSecret]);
            $source->name ??= $provider->getLabel();
            $source->save();
        }
    }
}
