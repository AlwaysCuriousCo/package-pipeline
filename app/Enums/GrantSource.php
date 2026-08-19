<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who wrote a row into one of the four grant pivots.
 *
 * Access in this registry has always been a grant: a row in `package_user`,
 * `repository_user`, `package_team` or `repository_team` that
 * Package::scopeVisibleToUser() folds into every read. Selling access adds a
 * second author for those rows — the entitlement projector — and the two must
 * not be able to overwrite each other.
 *
 * So every grant carries the hand that made it, and the composite unique on
 * each pivot includes it. A package can be granted to the same user twice, once
 * by an administrator and once by a subscription, and each can be withdrawn
 * without disturbing the other: cancelling a subscription must not revoke the
 * access somebody was given by name, and an administrator tidying up a grant
 * list must not silently un-sell something that has been paid for.
 *
 * The visibility scopes never look at this column. They ask which package ids a
 * user reaches, and the union that answers is documented as tolerating
 * duplicates — the caller only ever tests membership. That is what lets the
 * whole billing layer land without touching the hot path.
 *
 * @see \App\Services\Billing\EntitlementProjector
 * @see \App\Models\User::packageGrants()
 */
enum GrantSource: string implements HasLabel
{
    /** Written by a person in the panel, the API or a console command. */
    case Manual = 'manual';

    /** Written by the entitlement projector, on behalf of a subscription. */
    case Subscription = 'subscription';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Granted directly',
            self::Subscription => 'Granted by a subscription',
        };
    }

    /**
     * Whether a person editing grants in the panel may add or remove this kind
     * of row. Subscription grants are the projector's to write and nobody
     * else's — an administrator who wants one gone cancels or suspends the
     * subscription behind it, which is the record that will otherwise put it
     * straight back on the next reconcile.
     */
    public function editableByHand(): bool
    {
        return $this === self::Manual;
    }
}
