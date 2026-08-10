<?php

namespace App\Models;

use App\Enums\TokenAbility;
use App\Models\Concerns\LogsAuditableChanges;
use App\Support\NewToken;
use Database\Factories\TokenFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An access token a Composer client authenticates with.
 *
 * Consumers configure it as an HTTP Basic password (any username) or a
 * bearer token:
 *
 *   composer config http-basic.<registry-host> token <plain-token>
 *
 * The plain token exists only at issue time; the row keeps its sha256.
 * Revocation is a soft delete, which is also what findByPlainText() honours —
 * a revoked token stops authenticating without its history disappearing.
 */
#[Fillable(['name', 'abilities', 'expires_at'])]
class Token extends Model
{
    /** @use HasFactory<TokenFactory> */
    use HasFactory, LogsAuditableChanges, SoftDeletes;

    protected $table = 'access_tokens';

    /**
     * The credential's identity and reach — never `token`, which holds its
     * sha256, and never `last_used_at`, which every download moves.
     *
     * A roll is a delete and a create, so both halves land in the log with
     * the prefixes that tell an investigator which credential is which.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'token_prefix', 'abilities', 'expires_at', 'tokenable_type', 'tokenable_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The principal this token acts as: a User, or a DeployToken.
     *
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Issue a token for a principal, returning the only copy of its plain
     * text that will ever exist.
     *
     * @param  list<TokenAbility|string>  $abilities
     */
    public static function issue(
        Model $tokenable,
        string $name,
        array $abilities,
        ?DateTimeInterface $expiresAt = null,
    ): NewToken {
        $plain = 'pp_'.Str::random(40);

        $token = new self;

        $token->forceFill([
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'name' => $name,
            'abilities' => array_map(
                fn (TokenAbility|string $ability): string => $ability instanceof TokenAbility ? $ability->value : $ability,
                $abilities,
            ),
            'token_prefix' => substr($plain, 0, 8),
            'token' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
        ])->save();

        return new NewToken($token, $plain);
    }

    /**
     * The live token a client presented, or null when it matches nothing —
     * including anything revoked, which the soft delete keeps out of scope.
     */
    public static function findByPlainText(string $plain): ?self
    {
        return static::query()->where('token', hash('sha256', $plain))->first();
    }

    public function can(TokenAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Record that the token was just used, at most once a minute — Composer
     * fires a burst of requests per install, and one timestamp is the story.
     */
    public function markUsed(): void
    {
        if ($this->last_used_at?->gt(now()->subMinute())) {
            return;
        }

        // last_used_at is bookkeeping, not an edit; updated_at keeps meaning
        // "when the token itself last changed".
        static::withoutTimestamps(fn () => $this->forceFill(['last_used_at' => now()])->saveQuietly());
    }
}
