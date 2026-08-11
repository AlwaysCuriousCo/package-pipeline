<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use App\Models\Concerns\LogsGrantChanges;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsAuditableChanges, LogsGrantChanges, Notifiable;

    protected static function booted(): void
    {
        // Deleting the person revokes their credentials; the soft-deleted
        // token rows keep the audit trail of what they reached and when. An
        // orphaned token authenticates as nobody, which package scoping reads
        // as "the public packages" rather than as no access at all.
        // Deleted one at a time rather than in a single relation delete:
        // a mass delete fires no model events, and revocation is exactly the
        // sort of change the audit log exists to attribute.
        static::deleted(fn (self $user) => $user->tokens->each->delete());
    }

    /**
     * Determine whether the user may access the given Filament panel.
     *
     * A role is the entry ticket; what the user can do once inside is decided
     * by Shield's generated policies. Holding no role at all means the account
     * exists but cannot reach /admin, so a stray user row is never a way in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }

    /**
     * The personal access tokens this user authenticates Composer with.
     *
     * @return MorphMany<Token, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(Token::class, 'tokenable');
    }

    /**
     * Individual packages this user has been granted, over and above the
     * public repositories everyone sees.
     *
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * Private repositories this user has been granted wholesale.
     *
     * @return BelongsToMany<Repository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class);
    }

    /**
     * The teams this user belongs to, whose grants they hold on top of their
     * own.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * Every package this user has been granted individually — by name, or
     * through a team they belong to.
     *
     * This and its repository twin below are what package visibility is
     * decided from, so their shape matters as much as their contents. A union
     * of the two pivots rather than two more `orWhereIn` branches on the scope
     * itself: the scope runs on the Composer hot path, once per metadata and
     * per dist request, and teams must not make it wider than it was. As it
     * stands the scope has exactly the three branches it had before teams
     * existed — public, package grant, repository grant — and it is these
     * subqueries that grew instead.
     *
     * `unionAll` rather than `union`: a package granted both personally and
     * through a team appears twice, and the caller only ever asks whether an
     * id is in the list. Deduplicating would be a sort over the result for no
     * change in the answer.
     *
     * Ids from the pivots directly rather than through the relations, because
     * a relation would join `packages` to select a column the pivot already
     * holds — and the caller is matching against `packages.id` in a query that
     * has that table open already.
     *
     * **Use the result whole.** A constraint added to a query builder carrying
     * a union lands on the first select alone, so `packageGrants()->where(...)`
     * would filter the personal half and leave the team half wide open — which
     * is a hole that no test of the personal path would ever notice. Callers
     * that need to ask about one package ask isGrantedPackage() below.
     */
    public function packageGrants(): QueryBuilder
    {
        return DB::table('package_user')
            ->select('package_user.package_id')
            ->where('package_user.user_id', $this->getKey())
            ->unionAll(
                DB::table('package_team')
                    ->select('package_team.package_id')
                    ->join('team_user', 'team_user.team_id', '=', 'package_team.team_id')
                    ->where('team_user.user_id', $this->getKey()),
            );
    }

    /**
     * Every repository this user has been granted wholesale — by name, or
     * through a team.
     *
     * @see packageGrants() for why this is shaped the way it is
     */
    public function repositoryGrants(): QueryBuilder
    {
        return DB::table('repository_user')
            ->select('repository_user.repository_id')
            ->where('repository_user.user_id', $this->getKey())
            ->unionAll(
                DB::table('repository_team')
                    ->select('repository_team.repository_id')
                    ->join('team_user', 'team_user.team_id', '=', 'repository_team.team_id')
                    ->where('team_user.user_id', $this->getKey()),
            );
    }

    /**
     * The granted packages as packages, for the readers that need a column of
     * the row rather than its id.
     *
     * @return Builder<Package>
     */
    public function grantedPackages(): Builder
    {
        return Package::query()->whereIn('packages.id', $this->packageGrants());
    }

    /**
     * Whether this user holds a grant on one particular repository, their own
     * or a team's.
     *
     * The single-record question, asked as two existence checks rather than by
     * narrowing the union above — see packageGrants() for why constraining a
     * union query is a trap. This is the write path (an artifact upload, a
     * mutating API call), which happens once per publish rather than once per
     * dependency resolved, so a second indexed `exists` is not a cost worth
     * being clever about.
     */
    public function isGrantedRepository(Repository $repository): bool
    {
        return $this->repositories()->whereKey($repository->getKey())->exists()
            || $this->teams()
                ->whereHas('repositories', fn (Builder $query) => $query->whereKey($repository->getKey()))
                ->exists();
    }

    /**
     * Whether this user holds a grant on one particular package, their own or
     * a team's.
     */
    public function isGrantedPackage(Package $package): bool
    {
        return $this->packages()->whereKey($package->getKey())->exists()
            || $this->teams()
                ->whereHas('packages', fn (Builder $query) => $query->whereKey($package->getKey()))
                ->exists();
    }

    /**
     * Whether row-level package scoping applies to this user at all.
     *
     * Packistry's `unscoped`: a permission any role can carry (the super
     * admin's carries everything), checked wherever package visibility is
     * narrowed — not a role name, so custom roles can be given full sight
     * without inheriting administration.
     */
    public function hasUnscopedAccess(): bool
    {
        return $this->can('Unscoped:Package');
    }

    /**
     * Identity only. The password is a hashed cast and never logged; roles and
     * grants are not attributes at all and are recorded by
     * App\Listeners\LogRoleChange and LogsGrantChanges respectively.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'email'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
