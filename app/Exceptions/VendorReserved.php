<?php

namespace App\Exceptions;

use App\Models\ReservedVendor;
use RuntimeException;

/**
 * A package was about to be created or renamed under a vendor prefix that
 * belongs to another Composer repository.
 *
 * Raised by Package's own saving hook, so it is the last line rather than the
 * first: every caller that can say something more useful — a 422 on a form
 * field, a 403 from the upload endpoint — checks before saving and never sees
 * this. Where it does surface, its message is already written for whoever
 * reads it, which for a sync means the `sync_error` column.
 */
class VendorReserved extends RuntimeException
{
    public function __construct(
        public readonly ReservedVendor $reservation,
        public readonly string $name,
    ) {
        parent::__construct($reservation->refusal($name));
    }
}
