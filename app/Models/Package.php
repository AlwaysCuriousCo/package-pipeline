<?php

namespace App\Models;

use App\Enums\SourceProvider;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['source_id', 'repository', 'latest_version', 'name', 'description', 'type', 'token', 'last_synced_at', 'sync_error'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $package) => $package->linkSource());
    }

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Attach the connected source that owns this package's repository.
     *
     * Runs on every save, but only fills a source in when the package has none
     * *and* the repository URL is new — so a source chosen (or cleared) by
     * hand is never overwritten, and repointing a package at another
     * organisation is a deliberate act rather than a silent re-credentialing.
     */
    public function linkSource(): void
    {
        if ($this->source_id !== null || ! $this->isDirty('repository')) {
            return;
        }

        try {
            $path = $this->repositoryPath();
        } catch (InvalidArgumentException) {
            // An unparseable repository belongs to no source.
            return;
        }

        $this->source_id = Source::forRepositoryPath($path)?->id;
    }

    /**
     * The credential to use when talking to this package's provider.
     *
     * The connected source wins, because it is the credential an admin
     * deliberately attached to the whole organisation. A per-package token
     * remains as an override for repositories no source covers, and
     * GITHUB_TOKEN is the last resort.
     */
    public function accessToken(): ?string
    {
        return $this->source?->accessToken()
            ?? $this->token
            ?? config('services.github.token');
    }

    /**
     * The API root to reach this package's repository through, which a
     * self-hosted source overrides.
     */
    public function apiUrl(): string
    {
        return $this->source?->apiUrl() ?? SourceProvider::Github->defaultApiUrl();
    }

    /**
     * The "owner/repo" path of the GitHub repository.
     *
     * Accepts a full GitHub URL (https or git@, with or without .git) as well
     * as a bare "owner/repo" value.
     */
    public function repositoryPath(): string
    {
        $repository = trim($this->repository);

        if (preg_match('#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repository, $matches)) {
            return "{$matches[1]}/{$matches[2]}";
        }

        if (preg_match('#^([\w.-]+)/([\w.-]+?)(?:\.git)?$#', $repository, $matches)) {
            return "{$matches[1]}/{$matches[2]}";
        }

        throw new InvalidArgumentException("Unable to determine the GitHub repository from [{$repository}].");
    }

    /**
     * The Composer name this repository most likely publishes under.
     *
     * A guess for the create wizard, used only when the repository's own
     * composer.json cannot be read; the first sync replaces it with the real
     * name. Null when the repository URL names no GitHub repository at all.
     */
    public function suggestedName(): ?string
    {
        try {
            // Composer names are lowercase, GitHub paths need not be.
            return mb_strtolower($this->repositoryPath());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The distinct package types currently in use, keyed by value.
     *
     * `type` is a free-text column, so this powers the form's suggestions and
     * the table filter without hard-coding a vocabulary.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return static::query()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type', 'type')
            ->all();
    }
}
