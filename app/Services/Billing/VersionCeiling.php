<?php

namespace App\Services\Billing;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Support\VersionNormalizer;

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
