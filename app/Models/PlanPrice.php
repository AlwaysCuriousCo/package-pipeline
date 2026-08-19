<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way to pay for a plan: an amount, a currency, an interval.
 *
 * Rows rather than columns on the plan so one plan can sell monthly and
 * yearly at once, and so a price can retire without breaking the
 * subscriptions already on it — a retired price stops being offered and
 * keeps being charged.
 *
 * Amounts are integer minor units (1999 is $19.99), the only representation
 * of money that survives arithmetic, and the one merchants speak natively.
 */
#[Fillable(['currency', 'amount', 'interval', 'interval_count', 'active', 'default'])]
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval' => BillingInterval::class,
            'interval_count' => 'integer',
            'active' => 'boolean',
            'default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The amount as a human reads it — "$19.99/month" — for the panel and
     * the pricing page. Minor-unit maths stays here so nothing else divides
     * by 100 and gets a yen price wrong; the zero-decimal currencies are the
     * short list Stripe documents.
     */
    public function display(): string
    {
        $zeroDecimal = in_array($this->currency, ['jpy', 'krw', 'vnd', 'clp', 'pyg', 'rwf', 'ugx', 'vuv', 'xaf', 'xof', 'xpf', 'bif', 'djf', 'gnf', 'kmf', 'mga'], true);

        $amount = $zeroDecimal
            ? number_format($this->amount)
            : number_format($this->amount / 100, 2);

        $money = strtoupper($this->currency).' '.$amount;

        return match ($this->interval) {
            BillingInterval::OneTime => $money,
            default => $this->interval_count === 1
                ? "{$money}/{$this->interval->value}"
                : "{$money} every {$this->interval_count} {$this->interval->value}s",
        };
    }
}
