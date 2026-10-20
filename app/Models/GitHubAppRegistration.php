<?php

namespace App\Models;

use App\Enums\GitHubAppOwner;
use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\GitHubAppRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The GitHub App this registry authenticates as, registered from the panel.
 *
 * The same four values an operator would otherwise put in the environment —
 * id, private key, slug and webhook secret — captured from GitHub instead of
 * copied by hand. The environment still wins when it names an app, so a
 * deployment configured that way never reads this table; see GitHubApp.
 *
 * Only ever one row. Registering another app means forgetting this one first,
 * because every connected source is an installation of exactly one app.
 */
#[Fillable(['app_id', 'slug', 'name', 'owner', 'owner_type', 'html_url', 'private_key', 'webhook_secret'])]
class GitHubAppRegistration extends Model
{
    /** @use HasFactory<GitHubAppRegistrationFactory> */
    use HasFactory, LogsAuditableChanges;

    // "GitHub" is one word; the default would be git_hub_app_registrations.
    protected $table = 'github_app_registrations';

    /**
     * The registration in use, if any.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Which app, and whose. `private_key` and `webhook_secret` are encrypted
     * casts and never logged.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['app_id', 'slug', 'name', 'owner', 'owner_type', 'html_url'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => GitHubAppOwner::class,
            'private_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }
}
