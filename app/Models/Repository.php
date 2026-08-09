<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named Composer repository this registry serves.
 *
 * Every package belongs to exactly one repository. The default repository
 * (path null) answers at the site root, exactly as the registry did before
 * repositories existed; every other repository is mounted under /r/{path},
 * so one installation can serve independent registries — a public one and an
 * internal one, say — with independent access rules.
 */
#[Fillable(['name', 'path', 'description', 'public'])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * Narrow to the repositories a panel user may see: everything for a user
     * holding Unscoped:Package, otherwise public repositories plus the ones
     * their grants reach — granted wholesale, or holding a granted package.
     *
     * The repository-level counterpart of Package::scopeVisibleToUser().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if ($user->hasUnscopedAccess()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('public', true)
                ->orWhereIn('repositories.id', $user->repositories()->select('repositories.id'))
                ->orWhereIn('repositories.id', $user->packages()->select('packages.repository_id'));
        });
    }

    /**
     * The repository served at the site root, created on first use.
     *
     * Public by design: it stands in for the whole registry as it behaved
     * before repositories (and token auth) existed, so its packages stay
     * readable until an admin deliberately flips it private. Repositories
     * created by hand start private instead.
     */
    public static function default(): self
    {
        $existing = static::query()->whereNull('path')->first();

        if ($existing instanceof self) {
            return $existing;
        }

        return static::create([
            'name' => self::availableName('Default'),
            'path' => null,
            'description' => 'The repository served at the registry root.',
            'public' => true,
        ]);
    }

    /**
     * The repository mounted at the given URL path, or null when nothing is.
     * A null path names the default repository, creating it if need be.
     */
    public static function forPath(?string $path): ?self
    {
        return $path === null
            ? static::default()
            : static::query()->where('path', $path)->first();
    }

    /**
     * Whether this is the default repository, the one answering at the root.
     */
    public function isDefault(): bool
    {
        return $this->path === null;
    }

    /**
     * The URL path prefix this repository's Composer endpoints live under:
     * empty for the default repository, "/r/{path}" for a named one.
     */
    public function pathPrefix(): string
    {
        return $this->isDefault() ? '' : "/r/{$this->path}";
    }

    /**
     * An absolute URL inside this repository's mount.
     */
    public function url(string $suffix = ''): string
    {
        return url($this->pathPrefix().$suffix);
    }

    /**
     * The preferred name, suffixed until it no longer collides. Names are
     * unique, and default() must never fail because an admin happened to
     * call their own repository "Default".
     */
    private static function availableName(string $preferred): string
    {
        $name = $preferred;

        for ($suffix = 2; static::query()->where('name', $name)->exists(); $suffix++) {
            $name = "{$preferred} ({$suffix})";
        }

        return $name;
    }
}
