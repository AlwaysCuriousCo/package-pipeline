<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
