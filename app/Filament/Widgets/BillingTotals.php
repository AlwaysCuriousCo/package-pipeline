<?php

namespace App\Filament\Widgets;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers an operator checks daily: recurring revenue, live
 * subscriptions, trials about to convert or walk, and payments in trouble.
 *
 * MRR is computed from local rows — each active recurring subscription's
 * price normalised to a month — so it costs one query and no API call. It
 * is the operator's dial, not the accountant's number: proration, discounts
 * and tax live at the merchant.
 *
 * Hidden entirely while billing is disabled, and from anybody who cannot
 * see subscriptions.
 */
class BillingTotals extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return (bool) config('registry.billing.enabled')
            && (auth()->user()?->can('ViewAny:Subscription') ?? false);
    }

    protected function getStats(): array
    {
        $active = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
            ->whereNull('suspended_at')
            ->count();

        $trialsEnding = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
            ->count();

        $pastDue = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid])
            ->count();

        return [
            Stat::make('Monthly recurring revenue', $this->mrr()),
            Stat::make('Active subscriptions', (string) $active),
            Stat::make('Trials ending this week', (string) $trialsEnding),
            Stat::make('Past due', (string) $pastDue)
                ->color($pastDue > 0 ? 'danger' : 'success'),
        ];
    }

    private function mrr(): string
    {
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereNull('suspended_at')
            ->with('price')
            ->get();

        $byCurrency = [];

        foreach ($subscriptions as $subscription) {
            $price = $subscription->price;

            if ($price === null || ! $price->interval->recurring()) {
                continue;
            }

            $monthly = match ($price->interval) {
                BillingInterval::Month => $price->amount / max(1, $price->interval_count),
                BillingInterval::Year => $price->amount / (12 * max(1, $price->interval_count)),
                BillingInterval::OneTime => 0,
            };

            $byCurrency[$price->currency] = ($byCurrency[$price->currency] ?? 0)
                + (int) round($monthly * $subscription->quantity);
        }

        if ($byCurrency === []) {
            return '—';
        }

        return collect($byCurrency)
            ->map(fn (int $amount, string $currency): string => strtoupper($currency).' '.number_format($amount / 100, 2))
            ->join(' + ');
    }
}
