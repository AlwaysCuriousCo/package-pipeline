<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
    use HasFactory, LogsAuditableChanges;

    /**
     * Where a repository is served and whether it is readable without a
     * token — the switch that turns private packages public.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'path', 'public'];
    }

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
     * The vendor prefixes only this repository may introduce names under.
     *
     * @return HasMany<ReservedVendor, $this>
     */
    public function reservedVendors(): HasMany
    {
        return $this->hasMany(ReservedVendor::class);
    }

    /**
     * The Composer repositories this one mirrors packages from on demand,
     * in the order they are consulted.
     *
     * @return HasMany<Upstream, $this>
     */
    public function upstreams(): HasMany
    {
        return $this->hasMany(Upstream::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The upstreams actually in play — the disabled ones keep their cache but
     * are never reached for.
     *
     * @return Collection<int, Upstream>
     */
    public function activeUpstreams(): Collection
    {
        // Memoised on the relation rather than re-queried, because this is
        // asked once per Composer request and the answer cannot change inside
        // one. `mirrors()` below asks it too.
        return $this->upstreams->where('enabled', true)->values();
    }

    /**
     * Upstream documents cached for this repository, across every upstream.
     *
     * Only ever counted, and only in the panel — an operator asking "what is
     * this mirror actually holding" has no other way to find out, and the
     * answer is deliberately absent from list.json and search.json, which
     * describe what this registry publishes.
     *
     * @return HasManyThrough<MirroredPackage, Upstream, $this>
     */
    public function mirroredPackages(): HasManyThrough
    {
        return $this->hasManyThrough(MirroredPackage::class, Upstream::class);
    }

    /**
     * Upstream archives cached for this repository, which is where the disk
     * actually goes.
     *
     * @return HasManyThrough<MirroredArchive, Upstream, $this>
     */
    public function mirroredArchives(): HasManyThrough
    {
        return $this->hasManyThrough(MirroredArchive::class, Upstream::class);
    }

    /**
     * Whether this repository answers for packages it does not publish itself.
     *
     * Off unless an operator has said otherwise, which is what keeps an
     * existing installation behaving exactly as it did: with no upstream rows
     * there is nothing to consult, and every mirroring path returns before it
     * reaches the network.
     */
    public function mirrors(): bool
    {
        return $this->activeUpstreams()->isNotEmpty();
    }

    /**
     * Narrow to the repositories the presenting token may see, following
     * Package::scopeVisibleTo() principal for principal: a user's token sees
     * exactly what its owner does, an ungranted deploy token sees everything,
     * and anything else sees the public repositories plus its grants.
     *
     * A repository holding a granted package is visible too, because the
     * package inside it already is — hiding the mount a consumer is told to
     * configure would only make the grant unusable.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?Token $token): Builder
    {
        $principal = $token?->tokenable;

        if ($principal instanceof User) {
            return $query->visibleToUser($principal);
        }

        if ($principal instanceof DeployToken && ! $principal->isScoped()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($principal): void {
            $query->where('public', true);

            if ($principal instanceof DeployToken) {
                $query
                    ->orWhereIn('repositories.id', $principal->repositories()->select('repositories.id'))
                    ->orWhereIn('repositories.id', $principal->packages()->select('packages.repository_id'));
            }
        });
    }

    /**
     * Narrow to the repositories a panel user may see: everything for a user
     * holding Unscoped:Package, otherwise public repositories plus the ones
     * their grants reach — granted wholesale, or holding a granted package —
     * where "their grants" means their own and their teams' alike.
     *
     * The repository-level counterpart of Package::scopeVisibleToUser(), and
     * it has to stay one: a repository hidden from somebody who can see a
     * package inside it is a mount they are told to configure and cannot.
     *
     * Read by the surfaces that *serve* the registry — /api/v1/repositories,
     * through Token, and the Composer endpoints — where a listing is a
     * directory of what exists shown to a credential. Not by the panel's
     * Repositories screen, which is administration and is gated by
     * ViewAny:Repository instead; see RepositoryResource for why that is the
     * right gate there and this is not.
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
                ->orWhereIn('repositories.id', $user->repositoryGrants())
                // Through the packages rather than the pivot, because what is
                // wanted is the repository each granted package is served
                // from, and only the package row knows that.
                ->orWhereIn('repositories.id', $user->grantedPackages()->select('packages.repository_id'));
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
     *
     * Built from the configured application URL rather than from the request
     * that happens to be in flight, which is a security property and not a
     * tidiness one. The `dist` URLs these produce are baked into the `/p2`
     * body, so the base is folded into that response's ETag — and the ETag is
     * the payload cache's key. Resolved from the request, the base comes from
     * the `Host` header (or, since the app trusts every proxy,
     * `X-Forwarded-Host`), so an anonymous loop varying a header it fully
     * controls mints an unbounded number of cache entries, each holding up to
     * registry.metadata_cache.max_kilobytes for registry.metadata_cache.days.
     * The same forged base is what the victim's Composer would then be told to
     * download archives from.
     *
     * Where a registry answers is a deployment fact, and APP_URL is already
     * where this installation states it: the provider webhooks and the GitHub
     * App handshake are configured against it, and every URL this app builds
     * off the request (a queued notification's link, a password-setup link) is
     * built off it too once no request is in flight. An installation that has
     * not set it correctly has more visibly broken than this.
     *
     * trustHosts() would bound the header instead, and was not taken: it
     * answers 400 to anything that does not match, so an installation whose
     * APP_URL is stale or still the framework's default would serve nothing at
     * all rather than serve dist URLs from a host that at least resolves.
     */
    public function url(string $suffix = ''): string
    {
        return $this->rootUrl().$this->pathPrefix().$suffix;
    }

    /**
     * Where this registry states it is served from.
     *
     * The request is the fallback rather than the source, for an installation
     * that has emptied the setting outright — with nothing configured, the
     * host that asked is the only answer available.
     */
    private function rootUrl(): string
    {
        $configured = rtrim(trim((string) config('app.url')), '/');

        return $configured === '' ? rtrim(url('/'), '/') : $configured;
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
