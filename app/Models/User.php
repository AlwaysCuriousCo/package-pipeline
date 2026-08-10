<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsAuditableChanges, Notifiable;

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
     * Identity only. The password is a hashed cast and never logged; role
     * changes are not attributes at all and are recorded by
     * App\Listeners\LogRoleChange.
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
