<?php

namespace Database\Factories;

use App\Enums\GitHubAppOwner;
use App\Models\GitHubAppRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GitHubAppRegistration>
 */
class GitHubAppRegistrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'acme-pipeline-'.Str::lower(Str::random(6));

        return [
            'app_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'slug' => $slug,
            'name' => 'Acme Package Pipeline',
            'owner' => 'acme',
            'owner_type' => GitHubAppOwner::Organization,
            'html_url' => "https://github.com/apps/{$slug}",
            'private_key' => static::privateKey(),
            'webhook_secret' => Str::random(40),
        ];
    }

    /**
     * A freshly generated RSA key, so a registration can sign real JWTs.
     */
    public static function privateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
