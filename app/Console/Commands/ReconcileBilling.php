<?php

namespace App\Console\Commands;

use App\Enums\BillingModel;
use App\Enums\MerchantProvider;
use App\Enums\SubscriptionStatus;
use App\Models\MerchantReference;
use App\Models\Subscription;
use App\Notifications\Billing\SubscriptionLapsed;
use App\Notifications\Billing\TrialEnding;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\SubscriptionProjector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The safety net under the webhooks, and the clock for everything local.
 *
 * Three sweeps. Remote: every merchant-billed subscription the merchant
 * might still move is re-pulled and re-applied, which repairs whatever a
 * webhook lost during a deploy — the projector discards anything older than
 * what is already applied, so re-applying current truth is free. Local:
 * subscriptions whose own clocks crossed a boundary nobody webhooks about —
 * a Manual period ending, an updates window closing, a grace window running
 * out, an end-of-period cancellation arriving — are moved and re-projected.
 * Courtesy: trials ending inside a week get their one warning email.
 */
class ReconcileBilling extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Re-pull merchant-billed subscriptions, cross local billing clocks, and repair drift';

    public function handle(SubscriptionProjector $projector, EntitlementProjector $entitlements): int
    {
        if (! config('registry.billing.enabled')) {
            $this->info('Billing is disabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->reconcileRemote($projector);
        $this->crossLocalBoundaries($entitlements);
        $this->warnEndingTrials();

        return self::SUCCESS;
    }

    private function reconcileRemote(SubscriptionProjector $projector): void
    {
        $repaired = 0;
        $failed = 0;

        Subscription::query()
            ->reconcilable()
            ->with(['plan', 'customer'])
            ->chunkById(100, function ($subscriptions) use ($projector, &$repaired, &$failed): void {
                foreach ($subscriptions as $subscription) {
                    $externalId = MerchantReference::externalId($subscription, $subscription->merchant);

                    if ($externalId === null) {
                        continue;
                    }

                    try {
                        $projector->apply($subscription, $subscription->client()->fetchSubscription($externalId));
                        $repaired++;
                    } catch (Throwable $e) {
                        // One unreachable subscription must not stop the
                        // sweep; it is still here next run.
                        $this->warn("Could not reconcile subscription {$subscription->getKey()}: {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->info("Reconciled {$repaired} merchant-billed subscription(s)".($failed > 0 ? ", {$failed} unreachable" : '').'.');
    }

    /**
     * The boundaries only this app's own clock crosses.
     */
    private function crossLocalBoundaries(EntitlementProjector $entitlements): void
    {
        $moved = 0;

        // An end-of-period cancellation whose period has now ended. Manual
        // subscriptions cross it here; merchant-billed ones normally get the
        // merchant's own event first, and this catches the ones that didn't.
        $moved += $this->move(
            Subscription::query()
                ->whereNotNull('cancel_at')
                ->where('cancel_at', '<=', now())
                ->whereNotIn('status', [SubscriptionStatus::Canceled, SubscriptionStatus::Expired]),
            ['status' => SubscriptionStatus::Canceled, 'canceled_at' => now(), 'ends_at' => now()],
            $entitlements,
            notify: true,
        );

        // A Manual subscription (or a one-time purchase, which is Manual in
        // shape once bought) whose period ran out: Expired, which is the
        // lapse that FreezeAtVersion and the updates window hang off.
        $moved += $this->move(
            Subscription::query()
                ->whereNotNull('current_period_end')
                ->where('current_period_end', '<=', now())
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
                ->where(function ($query): void {
                    $query->where('merchant', MerchantProvider::Manual)
                        ->orWhereHas('plan', fn ($plan) => $plan->where('billing_model', BillingModel::OneTimeWithUpdates));
                }),
            ['status' => SubscriptionStatus::Expired, 'ends_at' => now()],
            $entitlements,
            notify: true,
        );

        // A grace window that ran out changes no column — grantsAccess()
        // already answers differently — but the pivots do not know that
        // until somebody re-projects.
        Subscription::query()
            ->whereNotNull('grace_ends_at')
            ->whereBetween('grace_ends_at', [now()->subHours((int) config('registry.billing.reconcile_lookback_hours')), now()])
            ->with('customer')
            ->chunkById(100, function ($subscriptions) use ($entitlements, &$moved): void {
                foreach ($subscriptions as $subscription) {
                    $entitlements->project($subscription);
                    $subscription->customer?->contact()?->notify(new SubscriptionLapsed($subscription));
                    $moved++;
                }
            });

        if ($moved > 0) {
            $this->info("Crossed {$moved} local billing boundary(ies).");
        }
    }

    /**
     * @param  Builder<Subscription>  $query
     * @param  array<string, mixed>  $to
     */
    private function move(Builder $query, array $to, EntitlementProjector $entitlements, bool $notify): int
    {
        $count = 0;

        $query->with(['plan', 'customer'])->chunkById(100, function ($subscriptions) use ($to, $entitlements, $notify, &$count): void {
            foreach ($subscriptions as $subscription) {
                $subscription->forceFill([...$to, 'last_event_at' => now()])->save();
                $entitlements->project($subscription);

                if ($notify) {
                    $subscription->customer?->contact()?->notify(new SubscriptionLapsed($subscription));
                }

                $count++;
            }
        });

        return $count;
    }

    /**
     * One warning per subscription, three days out: `trial_warned_at` would
     * be another column to keep true, so the window's width and the daily
     * cadence do the deduplication — a trial is inside [2, 3) days from its
     * end on exactly one nightly run.
     */
    private function warnEndingTrials(): void
    {
        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereBetween('trial_ends_at', [now()->addDays(2), now()->addDays(3)])
            ->with(['plan', 'customer'])
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    $subscription->customer?->contact()?->notify(new TrialEnding($subscription));
                }
            });
    }
}
