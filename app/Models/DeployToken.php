<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\DeployTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A machine principal for CI/CD access, without a user account behind it.
 *
 * The deploy token itself is the identity; what it presents to the Composer
 * endpoints is the access token issued against it. Its visibility is the
 * union of its granted repositories and packages — or everything, when it
 * holds no grants at all.
 */
#[Fillable(['name'])]
class DeployToken extends Model
{
    /** @use HasFactory<DeployTokenFactory> */
    use HasFactory, LogsAuditableChanges;

    protected static function booted(): void
    {
        // Deleting the principal revokes its credentials; the soft-deleted
        // token rows keep the audit trail of what it was and when it was
        // last used. The grant pivots cascade away in the database.
        // Deleted one at a time rather than in a single relation delete:
        // a mass delete fires no model events, and revocation is exactly the
        // sort of change the audit log exists to attribute.
        static::deleted(fn (self $deployToken) => $deployToken->tokens->each->delete());
    }

    /**
     * The principal itself. What it reaches lives in pivots rather than
     * columns, so a widened grant is attributed through the access token
     * issued against it rather than here.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name'];
    }

    /**
     * @return MorphMany<Token, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(Token::class, 'tokenable');
    }

    /**
     * The access token currently presented for this principal.
     *
     * @return MorphOne<Token, $this>
     */
    public function token(): MorphOne
    {
        return $this->morphOne(Token::class, 'tokenable')->latestOfMany();
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * @return BelongsToMany<Repository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class);
    }

    /**
     * Whether this token's reach is limited at all. No grants means every
     * package — an explicit design choice, so that "one token for the whole
     * registry" needs no ceremony.
     */
    public function isScoped(): bool
    {
        return $this->packages()->exists() || $this->repositories()->exists();
    }
}
