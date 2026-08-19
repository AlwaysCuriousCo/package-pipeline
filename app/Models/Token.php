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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
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
     * The subscription this token was issued under, when it was.
     *
     * Set for the activation token a purchase mints and for any token counted
     * against a plan's token_limit; null for every token that predates
     * billing or was issued outside it. What LapseBehaviour::RevokeTokens
     * revokes is exactly the tokens carrying its subscription's id — never a
     * personal token the same user issued for something else.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
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
        ?Subscription $subscription = null,
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
            'subscription_id' => $subscription?->getKey(),
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

    /**
     * Whether this token's principal may create something in a repository.
     *
     * An ability says "may write"; this says where. It is the write-side
     * counterpart of Package::scopeVisibleTo(), and deliberately not its
     * mirror: a public repository is readable by anyone, which grants nothing
     * about writing into it, so `public` never appears below.
     *
     * This is the grant half on its own, and the whole answer on the Composer
     * surface, where publishing is governed by grants exactly as reading is.
     * The management API mirrors the panel as well, and asks through the three
     * action methods below — an endpoint acting for a person must use one of
     * those rather than this, or a role that cannot delete a package in the
     * panel deletes one over HTTP.
     *
     * A user's grant is their own or their team's, without distinction. A team
     * holds the same grants a person can be given individually — that is the
     * whole of what a team is — so a grant that conferred publishing rights
     * one way and not the other would be a second, invisible kind of grant.
     * Deploy tokens have no teams: they authenticate a machine, which cannot
     * be a member of anything.
     */
    public function mayWriteTo(Repository $repository): bool
    {
        $principal = $this->tokenable;

        if ($principal instanceof User) {
            return $principal->hasUnscopedAccess()
                || $principal->isGrantedRepository($repository);
        }

        if ($principal instanceof DeployToken) {
            return ! $principal->isScoped()
                || $principal->repositories()->whereKey($repository->getKey())->exists();
        }

        return false;
    }

    /**
     * Whether this token's principal may change an existing package.
     *
     * Wider than mayWriteTo() by exactly one case: a principal granted this
     * package alone reaches it without reaching the repository around it,
     * which is what a per-package deploy grant is for.
     *
     * The grant half only, as mayWriteTo() is.
     */
    public function mayWriteToPackage(Package $package): bool
    {
        if ($this->mayWriteTo($package->composerRepository)) {
            return true;
        }

        $principal = $this->tokenable;

        if ($principal instanceof User) {
            return $principal->isGrantedPackage($package);
        }

        return $principal instanceof DeployToken
            && $principal->packages()->whereKey($package->getKey())->exists();
    }

    /**
     * Whether this token may create a package in this repository — the grant
     * that says where, and the permission the panel's create page would ask
     * of the person behind it.
     */
    public function mayCreatePackageIn(Repository $repository): bool
    {
        return $this->mayWriteTo($repository) && $this->principalMay('create', Package::class);
    }

    /**
     * Whether this token may queue a sync for this package.
     *
     * The grant alone, and not because syncing is harmless: it is what the
     * panel asks too. Its SyncPackageAction carries no permission of its own —
     * a user who can open the record can re-pull its versions — so requiring
     * one here would make the API stricter than the screen it stands in for,
     * which is a different bug in the same family.
     */
    public function maySyncPackage(Package $package): bool
    {
        return $this->mayWriteToPackage($package);
    }

    /**
     * Whether this token may delete this package — the grant, and the
     * permission the panel's delete action would ask of the person behind it.
     */
    public function mayDeletePackage(Package $package): bool
    {
        return $this->mayWriteToPackage($package) && $this->principalMay('delete', $package);
    }

    /**
     * Whether the principal behind this token holds the panel permission an
     * action needs, on top of the grant that says where it may write.
     *
     * The two are different questions and both have to be yes for a person: a
     * grant says which packages are theirs to touch, a role says what touching
     * is allowed to mean. A personal access token is its owner acting through
     * a machine and carries their role unchanged, so ticking `api:delete` on a
     * token cannot buy what Delete:Package would have to.
     *
     * A deploy token is the other kind of principal entirely — a machine
     * credential that holds no role and could not be given one — so no policy
     * is asked of it and its grants remain the whole of its authority. Asking
     * anyway would answer no to everything and turn off every deploy token in
     * the registry.
     *
     * @param  Package|class-string<Package>  $subject
     */
    private function principalMay(string $ability, Package|string $subject): bool
    {
        $principal = $this->tokenable;

        return ! $principal instanceof User
            || Gate::forUser($principal)->allows($ability, $subject);
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
