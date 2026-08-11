<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\LicenseUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What this registry publishes, by licence.
 *
 * Three questions, all of them about the whole registry at once: which
 * licences are in use, how much of the registry each accounts for, and how
 * much of it declares none. The last is the one that matters most in practice
 * — a version with no `license` in its composer.json is not permissively
 * licensed, it is unlicensed, and a consuming project that vendors it has no
 * grant at all.
 *
 * Answered from the `license` column rather than from `metadata`, which is why
 * that column exists: this is a group-by over an index instead of a decode of
 * every composer.json the registry stores.
 *
 * @see docs/licensing.md
 */
class LicenseReport
{
    public function __construct(
        /**
         * Whose visibility to apply, or null for a caller that has none —
         * the console, which has no session to scope to.
         */
        private readonly ?User $user = null,
    ) {}

    /**
     * One row per licence in use, widest first, with the number of packages
     * and versions carrying it. Versions declaring no licence at all are one
     * of these rows, with a null licence.
     *
     * @return Collection<int, LicenseUsage>
     */
    public function summary(): Collection
    {
        return $this->versions()
            ->select([
                'license',
                DB::raw('count(*) as versions'),
                DB::raw('count(distinct package_id) as packages'),
            ])
            ->groupBy('license')
            // By reach rather than alphabetically: the first thing anyone
            // wants from this is "what is most of the registry under", and the
            // second is "what is the odd one out". Both are at the ends.
            // Ordered by the expressions rather than by the aliases, which
            // not every engine admits in an ORDER BY.
            ->orderByRaw('count(distinct package_id) desc')
            ->orderByRaw('count(*) desc')
            ->orderBy('license')
            ->get()
            ->map(fn (PackageVersion $row): LicenseUsage => new LicenseUsage(
                license: $row->license,
                packages: (int) $row->getAttribute('packages'),
                versions: (int) $row->getAttribute('versions'),
            ));
    }

    /**
     * The distinct licences in use, as a select's options — the panel filter's
     * vocabulary, which is the registry's own rather than a hard-coded list.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->versions()
            ->whereNotNull('license')
            ->distinct()
            ->orderBy('license')
            ->pluck('license', 'license')
            ->all();
    }

    /**
     * How many versions are in scope, and how many of those say nothing about
     * their licensing.
     *
     * @return array{versions: int, undeclared: int, packages: int}
     */
    public function totals(): array
    {
        $row = $this->versions()
            ->select([
                DB::raw('count(*) as versions'),
                DB::raw('count(distinct package_id) as packages'),
                // A conditional count rather than a second query, and spelled
                // with case/end because count(license) would exclude nulls on
                // every engine but say so in none of them.
                DB::raw('sum(case when license is null then 1 else 0 end) as undeclared'),
            ])
            ->first();

        return [
            'versions' => (int) ($row?->getAttribute('versions') ?? 0),
            'packages' => (int) ($row?->getAttribute('packages') ?? 0),
            'undeclared' => (int) ($row?->getAttribute('undeclared') ?? 0),
        ];
    }

    /**
     * The versions this report may see — every question above narrows this
     * one, so visibility is decided once.
     *
     * @return Builder<PackageVersion>
     */
    public function versions(): Builder
    {
        return PackageVersion::query()->ofPackages($this->packages());
    }

    /**
     * @return Builder<Package>
     */
    private function packages(): Builder
    {
        $packages = Package::query();

        return $this->user === null ? $packages : $packages->visibleToUser($this->user);
    }
}
