<?php

namespace App\Services\Billing;

use App\Models\BillingCustomer;
use App\Models\Entitlement;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Team;
use App\Models\Token;
use App\Models\User;
use App\Support\VersionNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Computes and applies the version ceiling a frozen entitlement carries.
 *
 * A ceiling is stored as VersionNormalizer::order()'s sortable spelling of
 * the highest release that existed when the entitlement froze — not the
 * human version string — because that is the one representation the metadata
 * path can filter by in SQL (`order` <= ceiling) without parsing semver per
 * row per request. package_versions.order is the same spelling, which is the
 * whole point.
 *
 * Dev versions are never inside a ceiling. A branch has no position on a
 * release line — dev-main today contains code newer than any pinned release,
 * so admitting it would hand a lapsed licence the ongoing work the ceiling
 * exists to close off.
 */
final class VersionCeiling
{
    /**
     * A ceiling below every real version, for a package that had no releases
     * at freeze time. Null means "no ceiling", so nothing-yet needs its own
     * spelling: '!' sorts beneath the zero-padded digits every real order
     * string starts with.
     */
    public const string NOTHING = '!';

    public function __construct(private readonly VersionNormalizer $normalizer = new VersionNormalizer) {}

    /**
     * The ceiling that pins a package right now: the sortable spelling of
     * its highest released version.
     */
    public function currentCeiling(Package $package): string
    {
        $ceiling = PackageVersion::query()
            ->where('package_id', $package->getKey())
            ->where('is_dev', false)
            ->whereNotNull('order')
            ->max('order');

        if (is_string($ceiling)) {
            return $ceiling;
        }

        // Rows synced before the order column existed have none until the
        // next sync backfills it; compute what it will say rather than
        // handing out no ceiling at all, which is the one wrong direction.
        $versions = PackageVersion::query()
            ->where('package_id', $package->getKey())
            ->where('is_dev', false)
            ->pluck('version');

        $orders = $versions->map(fn (string $version): string => $this->normalizer->order($version));

        return $orders->max() ?? self::NOTHING;
    }

    /**
     * The ceiling that applies to this credential for this package — null
     * for the overwhelming majority of requests, which is also the answer
     * that costs one indexed query.
     *
     * A ceiling applies only when a frozen entitlement is the *only* reason
     * the caller can see the package. Every wider path wins over it: an
     * anonymous reader of a public repository, a deploy token, an unscoped
     * role, a manual grant (personal or a team's), or any live subscription
     * granting the same package uncapped. Where several frozen entitlements
     * apply, the most generous ceiling does.
     *
     * Asked on the metadata and dist paths, so the order of checks is the
     * cost model: the entitlement lookup runs first because "no ceilinged
     * entitlement" — everyone who never bought a perpetual licence — answers
     * in that one query, and the manual-grant checks run only behind it.
     */
    public function ceilingFor(?Token $token, Package $package): ?string
    {
        $principal = $token?->tokenable;

        if (! $principal instanceof User) {
            // Anonymous readers reach only public repositories, and deploy
            // tokens are machine credentials no subscription ever issued.
            return null;
        }

        if ($principal->hasUnscopedAccess()) {
            return null;
        }

        if ($package->composerRepository->public) {
            return null;
        }

        $ceilings = Entitlement::query()
            ->where('active', true)
            ->whereIn('billing_customer_id', $this->customerIdsFor($principal))
            ->where(function ($query) use ($package): void {
                $query
                    ->where(fn ($q) => $q
                        ->where('grantable_type', Package::class)
                        ->where('grantable_id', $package->getKey()))
                    ->orWhere(fn ($q) => $q
                        ->where('grantable_type', Repository::class)
                        ->where('grantable_id', $package->repository_id));
            })
            ->pluck('version_ceiling');

        // No entitlement reaches this package, or a live one grants it
        // uncapped — either way the subscription layer imposes nothing.
        if ($ceilings->isEmpty() || $ceilings->containsStrict(null)) {
            return null;
        }

        if ($this->hasManualGrant($principal, $package)) {
            return null;
        }

        return $ceilings->max();
    }

    /**
     * The billing customers whose entitlements reach this user: their own,
     * and every team's they belong to. A subquery rather than a fetched
     * list, so the whole resolution stays one round trip.
     */
    private function customerIdsFor(User $user)
    {
        return BillingCustomer::query()
            ->select('id')
            ->where(function ($query) use ($user): void {
                $query
                    ->where(fn ($q) => $q
                        ->where('billable_type', User::class)
                        ->where('billable_id', $user->getKey()))
                    ->orWhere(fn ($q) => $q
                        ->where('billable_type', Team::class)
                        ->whereIn('billable_id', DB::table('team_user')
                            ->select('team_id')
                            ->where('user_id', $user->getKey())));
            });
    }

    /**
     * Whether a hand-made grant reaches this package — the same four paths
     * User::isGrantedPackage() and isGrantedRepository() walk, narrowed to
     * rows the panel wrote, because a subscription row here would make the
     * frozen entitlement neutralise its own ceiling.
     */
    private function hasManualGrant(User $user, Package $package): bool
    {
        return $user->packages()->whereKey($package->getKey())->wherePivot('source', 'manual')->exists()
            || $user->repositories()->whereKey($package->repository_id)->wherePivot('source', 'manual')->exists()
            || $user->teams()
                ->whereHas('packages', fn ($query) => $query
                    ->whereKey($package->getKey())
                    ->where('package_team.source', 'manual'))
                ->exists()
            || $user->teams()
                ->whereHas('repositories', fn ($query) => $query
                    ->whereKey($package->repository_id)
                    ->where('repository_team.source', 'manual'))
                ->exists();
    }

    /**
     * Whether a version is inside a ceiling. Null ceiling means unlimited —
     * the shape every live subscription and every manual grant has.
     */
    public function permits(PackageVersion $version, ?string $ceiling): bool
    {
        if ($ceiling === null) {
            return true;
        }

        if ($version->is_dev) {
            return false;
        }

        $order = $version->order ?? $this->normalizer->order($version->version);

        return $order <= $ceiling;
    }
}
