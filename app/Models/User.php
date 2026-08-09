<?php

namespace App\Models;

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
    use HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        // Deleting the person revokes their credentials; the soft-deleted
        // token rows keep the audit trail of what they reached and when. An
        // orphaned token authenticates as nobody, which package scoping reads
        // as "the public packages" rather than as no access at all.
        static::deleted(fn (self $user) => $user->tokens()->delete());
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
