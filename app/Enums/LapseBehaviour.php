<?php

namespace App\Enums;

use App\Services\Billing\EntitlementProjector;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What a subscription's lapse does to the access it granted.
 *
 * A property of the plan, not of the code: the same registry can sell a
 * strict per-seat tool that cuts off on non-payment next to a perpetual
 * licence that keeps every version its buyer ever paid for. The projector
 * reads this once, at the moment a subscription stops granting access, and
 * does exactly one of these four things.
 *
 * Whatever the choice, renewal reverses it. Withdrawn grants are re-projected,
 * revoked tokens are the one exception — a soft-deleted credential is not
 * un-deleted, because its plain text is long gone; the customer issues a new
 * one and the old row stays in the audit log.
 *
 * @see EntitlementProjector
 */
enum LapseBehaviour: string implements HasDescription, HasLabel
{
    /**
     * Remove the subscription's grants. Tokens survive and keep reaching
     * whatever else their owner is granted; the paid packages simply stop
     * being visible. The default, because it is the only choice that is both
     * complete and perfectly reversible.
     */
    case WithdrawEntitlement = 'withdraw_entitlement';

    /**
     * Withdraw the grants and soft-delete every token the subscription
     * issued. For plans whose token is the product — where a credential
     * shared around a team is the abuse being priced against.
     */
    case RevokeTokens = 'revoke_tokens';

    /**
     * Keep the grants, pin a version ceiling. Everything released while the
     * subscription was paid stays installable forever; anything newer needs a
     * live subscription. The lapse behaviour of a perpetual licence, and the
     * implied choice for BillingModel::OneTimeWithUpdates.
     */
    case FreezeAtVersion = 'freeze_at_version';

    /**
     * Do nothing. The subscription record lapses, the access stays. For
     * plans that are really donations, sponsorships or support contracts,
     * where cutting anything off would be the wrong answer.
     */
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::WithdrawEntitlement => 'Withdraw access',
            self::RevokeTokens => 'Withdraw access and revoke tokens',
            self::FreezeAtVersion => 'Freeze at the versions already released',
            self::None => 'Keep access',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::WithdrawEntitlement => 'The plan\'s packages stop being visible. Tokens keep working for anything else their owner can reach, and renewal restores everything.',
            self::RevokeTokens => 'Access is withdrawn and every token this subscription issued is revoked. After renewal the customer must issue new tokens.',
            self::FreezeAtVersion => 'Versions released while the subscription was active remain installable forever; newer releases require renewing.',
            self::None => 'Nothing is withdrawn. Use for sponsorships and goodwill plans where access should outlive the payments.',
        };
    }

    /**
     * Whether lapsing under this behaviour leaves the subscription's grants
     * in place. The projector uses this to decide between deleting its pivot
     * rows and keeping them alongside a ceiling.
     */
    public function keepsGrants(): bool
    {
        return match ($this) {
            self::FreezeAtVersion, self::None => true,
            self::WithdrawEntitlement, self::RevokeTokens => false,
        };
    }
}
