<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['repository', 'latest_version', 'name', 'description', 'type', 'token', 'last_synced_at', 'sync_error'])]
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

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class);
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
