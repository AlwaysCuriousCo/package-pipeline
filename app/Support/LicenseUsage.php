<?php

namespace App\Support;

use App\Services\LicenseReport;

/**
 * How much of the registry one licence accounts for.
 *
 * A named type rather than a row or an array because it is read in a Blade
 * template and in a table, and "which of these two counts is which" is exactly
 * the question an array of ints leaves open.
 *
 * @see LicenseReport::summary()
 */
readonly class LicenseUsage
{
    public function __construct(
        /**
         * The SPDX expression, or null for the versions declaring nothing —
         * which is a finding rather than a missing row, and is why the null is
         * carried rather than filtered out.
         */
        public ?string $license,
        public int $packages,
        public int $versions,
    ) {}

    /**
     * What to call this in a listing, including the case that has no name.
     */
    public function label(): string
    {
        return $this->license ?? 'Not declared';
    }
}
