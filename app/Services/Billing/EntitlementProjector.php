<?php

namespace App\Services\Billing;

use App\Enums\GrantSource;
use App\Enums\LapseBehaviour;
use App\Models\BillingCustomer;
use App\Models\Entitlement;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns subscriptions into grants — the one writer of every pivot row whose
 * source says 'subscription'.
 *
 * Billing never gets a branch in the visibility scopes. What a subscription
 * grants is written into the same four pivot tables manual grants live in,
 * marked with its source, and everything downstream — the Composer surface,
 * the panel, the exports — keeps reading exactly what it always read. This
 * class is the projection: given a customer, it computes what their
 * subscriptions currently grant and makes the pivots say so, no more and no
 * less. Manual rows are never touched in either direction.
 *
 * Idempotent by construction. It computes the desired state and diffs, so
 * running it twice is running it once, and it is safe to call on every
 * webhook, every reconcile, and every plan edit. It projects the *customer*,
 * not the subscription that changed: two subscriptions can grant the same
 * package, and only the union says whether the pivot row stays.
 *
 * The Entitlement ledger is maintained alongside: which subscription granted
 * what, and up to which version. The pivots answer "who sees what" on the
 * hot path; the ledger answers the questions only billing asks.
 *
 * @see GrantSource for the two-writers contract
 * @see docs/plans/ecommerce-subscriptions.md
 */
final class EntitlementProjector
{
    public function __construct(
        private readonly VersionCeiling $ceilings = new VersionCeiling,
    ) {}

    /** Re-project the customer behind one subscription that changed. */
    public function project(Subscription $subscription): void
    {
        $customer = $subscription->customer;

        if ($customer !== null) {
            $this->projectCustomer($customer);
        }
    }

    /**
     * Recompute every subscription-sourced grant this customer's billable
     * should hold, and make the ledger and the pivots agree.
     */
    public function projectCustomer(BillingCustomer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $desired = [];

            $subscriptions = $customer->subscriptions()
                ->with(['plan.entitlements', 'entitlements'])
                ->get();

            foreach ($subscriptions as $subscription) {
                foreach ($this->syncLedger($subscription) as $key => $pair) {
                    $desired[$key] = $pair;
                }
            }

            $this->syncPivots($customer, $desired);
        });
    }

    /**
     * Bring one subscription's ledger rows in line with its status, and
     * return the grants it currently supports as "type:id" keyed pairs.
     *
     * @return array<string, array{class-string, int}>
     */
    private function syncLedger(Subscription $subscription): array
    {
        // Suspension is the administrative hard stop: it bypasses the plan's
        // lapse behaviour on purpose, because softening a suspension with a
        // frozen-ceiling keepsake would defeat the act.
        if ($subscription->suspended_at !== null) {
            $this->deactivateAll($subscription);

            return [];
        }

        if ($subscription->grantsAccess()) {
            return $this->activate($subscription);
        }

        // Never activated: a checkout that expired incomplete, a subscription
        // created lapsed. There is nothing to withdraw, freeze or keep.
        if ($subscription->entitlements->isEmpty()) {
            return [];
        }

        return match ($subscription->plan->lapse_behaviour) {
            LapseBehaviour::None => $this->keep($subscription),
            LapseBehaviour::FreezeAtVersion => $this->freeze($subscription),
            LapseBehaviour::WithdrawEntitlement => $this->withdraw($subscription),
            LapseBehaviour::RevokeTokens => $this->withdrawAndRevoke($subscription),
        };
    }

    /**
     * The subscription grants: ensure a live, uncapped ledger row per plan
     * entitlement, and retire rows the plan no longer names — which is also
     * what unwinds a freeze after a renewal, since the expanded per-package
     * rows a frozen repository grant left behind are not in the plan.
     *
     * @return array<string, array{class-string, int}>
     */
    private function activate(Subscription $subscription): array
    {
        $pairs = [];

        foreach ($subscription->plan->entitlements as $planned) {
            $subscription->entitlements()->updateOrCreate(
                [
                    'grantable_type' => $planned->grantable_type,
                    'grantable_id' => $planned->grantable_id,
                ],
                [
                    'billing_customer_id' => $subscription->billing_customer_id,
                    'active' => true,
                    'version_ceiling' => null,
                    'starts_at' => $subscription->entitlements
                        ->first(fn (Entitlement $row): bool => $row->grantable_type === $planned->grantable_type
                            && $row->grantable_id === $planned->grantable_id)
                        ->starts_at ?? now(),
                    'ends_at' => null,
                ],
            );

            $pairs[$this->key($planned->grantable_type, $planned->grantable_id)] = [$planned->grantable_type, $planned->grantable_id];
        }

        $subscription->entitlements()
            ->where('active', true)
            ->where(function ($query) use ($subscription): void {
                foreach ($subscription->plan->entitlements as $planned) {
                    $query->whereNot(fn ($row) => $row
                        ->where('grantable_type', $planned->grantable_type)
                        ->where('grantable_id', $planned->grantable_id));
                }
            })
            ->update(['active' => false, 'ends_at' => now()]);

        return $pairs;
    }

    /**
     * LapseBehaviour::None — the rows stay exactly as they are.
     *
     * @return array<string, array{class-string, int}>
     */
    private function keep(Subscription $subscription): array
    {
        $pairs = [];

        foreach ($subscription->entitlements()->where('active', true)->get() as $row) {
            $pairs[$this->key($row->grantable_type, (int) $row->grantable_id)] = [$row->grantable_type, (int) $row->grantable_id];
        }

        return $pairs;
    }

    /**
     * LapseBehaviour::FreezeAtVersion — pin every active grant at the highest
     * version that exists right now.
     *
     * A package grant gets its ceiling in place. A repository grant means
     * "every package, present and future", which a frozen licence is exactly
     * not — so it is expanded into per-package rows, each with its own
     * ceiling, and the repository row is retired. A package added to the
     * repository after the freeze is never granted; renewal restores the
     * repository row through activate() and retires the expansion.
     *
     * Ceilings are ??= — a freeze that runs twice must not move the pin.
     *
     * @return array<string, array{class-string, int}>
     */
    private function freeze(Subscription $subscription): array
    {
        $pairs = [];

        foreach ($subscription->entitlements()->where('active', true)->get() as $row) {
            if ($row->grantable_type === Package::class) {
                $package = Package::query()->find($row->grantable_id);

                $row->forceFill([
                    'version_ceiling' => $row->version_ceiling
                        ?? ($package !== null ? $this->ceilings->currentCeiling($package) : VersionCeiling::NOTHING),
                    'ends_at' => $row->ends_at ?? now(),
                ])->save();

                $pairs[$this->key(Package::class, (int) $row->grantable_id)] = [Package::class, (int) $row->grantable_id];

                continue;
            }

            foreach (Package::query()->where('repository_id', $row->grantable_id)->get() as $package) {
                $subscription->entitlements()->updateOrCreate(
                    [
                        'grantable_type' => Package::class,
                        'grantable_id' => $package->getKey(),
                    ],
                    [
                        'billing_customer_id' => $subscription->billing_customer_id,
                        'active' => true,
                        'version_ceiling' => $this->ceilings->currentCeiling($package),
                        'starts_at' => $row->starts_at,
                        'ends_at' => now(),
                    ],
                );

                $pairs[$this->key(Package::class, (int) $package->getKey())] = [Package::class, (int) $package->getKey()];
            }

            $row->forceFill(['active' => false, 'ends_at' => $row->ends_at ?? now()])->save();
        }

        return $pairs;
    }

    /**
     * @return array<string, array{class-string, int}>
     */
    private function withdraw(Subscription $subscription): array
    {
        $this->deactivateAll($subscription);

        return [];
    }

    /**
     * @return array<string, array{class-string, int}>
     */
    private function withdrawAndRevoke(Subscription $subscription): array
    {
        $this->deactivateAll($subscription);

        // One at a time so each revocation lands in the audit log — the same
        // reason User::booted() deletes tokens the slow way.
        $subscription->tokens()->get()->each->delete();

        return [];
    }

    private function deactivateAll(Subscription $subscription): void
    {
        $subscription->entitlements()
            ->where('active', true)
            ->update(['active' => false, 'ends_at' => now()]);
    }

    /**
     * Make the billable's subscription-sourced pivot rows match the desired
     * set. A User's grants go in the user pivots; a Team's in the team
     * pivots, where membership churn keeps working exactly as it does for
     * manual team grants. Rows whose source is 'manual' are invisible here.
     *
     * @param  array<string, array{class-string, int}>  $desired
     */
    private function syncPivots(BillingCustomer $customer, array $desired): void
    {
        $billable = $customer->billable;

        if (! $billable instanceof User && ! $billable instanceof Team) {
            return;
        }

        [$packageTable, $repositoryTable, $ownerColumn] = $billable instanceof Team
            ? ['package_team', 'repository_team', 'team_id']
            : ['package_user', 'repository_user', 'user_id'];

        $packages = [];
        $repositories = [];

        foreach ($desired as [$type, $id]) {
            if ($type === Package::class) {
                $packages[] = $id;
            } elseif ($type === Repository::class) {
                $repositories[] = $id;
            }
        }

        $changes = [
            'packages' => $this->syncPivotTable($packageTable, 'package_id', $ownerColumn, $billable->getKey(), $packages),
            'repositories' => $this->syncPivotTable($repositoryTable, 'repository_id', $ownerColumn, $billable->getKey(), $repositories),
        ];

        // The pivot writes bypass the relations, so the audit trail that
        // AuditedBelongsToMany gives manual grants is written by hand here —
        // one line per projection that changed anything, attributing the
        // change to the subscriptions rather than to a person.
        $added = $changes['packages']['added'] + $changes['repositories']['added'];
        $removed = $changes['packages']['removed'] + $changes['repositories']['removed'];

        if ($added > 0 || $removed > 0) {
            activity('audit')
                ->performedOn($billable)
                ->event('subscription_grants_projected')
                ->withProperties([
                    'billing_customer_id' => $customer->getKey(),
                    'packages_granted' => $changes['packages']['added'],
                    'packages_withdrawn' => $changes['packages']['removed'],
                    'repositories_granted' => $changes['repositories']['added'],
                    'repositories_withdrawn' => $changes['repositories']['removed'],
                ])
                ->log('subscription_grants_projected');
        }
    }

    /**
     * @param  list<int>  $desiredIds
     * @return array{added: int, removed: int}
     */
    private function syncPivotTable(string $table, string $targetColumn, string $ownerColumn, int|string $ownerId, array $desiredIds): array
    {
        $desiredIds = array_values(array_unique($desiredIds));

        $existing = DB::table($table)
            ->where($ownerColumn, $ownerId)
            ->where('source', GrantSource::Subscription->value)
            ->pluck($targetColumn)
            ->all();

        $missing = array_diff($desiredIds, $existing);
        $stale = array_diff($existing, $desiredIds);

        if ($stale !== []) {
            DB::table($table)
                ->where($ownerColumn, $ownerId)
                ->where('source', GrantSource::Subscription->value)
                ->whereIn($targetColumn, $stale)
                ->delete();
        }

        if ($missing !== []) {
            DB::table($table)->insert(array_map(
                fn (int $id): array => [
                    $targetColumn => $id,
                    $ownerColumn => $ownerId,
                    'source' => GrantSource::Subscription->value,
                ],
                array_values($missing),
            ));
        }

        return ['added' => count($missing), 'removed' => count($stale)];
    }

    private function key(string $type, int|string $id): string
    {
        return "{$type}:{$id}";
    }
}
