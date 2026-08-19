<?php

namespace Database\Factories;

use App\Enums\BillingModel;
use App\Enums\CancellationTiming;
use App\Enums\LapseBehaviour;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'active' => true,
            'listed' => false,
            'billing_model' => BillingModel::Recurring,
            'trial_days' => 0,
            'lapse_behaviour' => LapseBehaviour::WithdrawEntitlement,
            'cancellation' => CancellationTiming::Immediate,
            'auto_issue_token' => true,
        ];
    }

    public function listed(): static
    {
        return $this->state(fn (): array => ['listed' => true]);
    }

    public function perpetual(int $updatesWindowMonths = 12): static
    {
        return $this->state(fn (): array => [
            'billing_model' => BillingModel::OneTimeWithUpdates,
            'updates_window_months' => $updatesWindowMonths,
            'lapse_behaviour' => LapseBehaviour::FreezeAtVersion,
        ]);
    }
}
