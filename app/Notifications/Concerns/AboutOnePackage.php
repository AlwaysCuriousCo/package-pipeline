<?php

namespace App\Notifications\Concerns;

use App\Models\Package;
use App\Models\Repository;

/**
 * The webhook scope of a notification whose subject is a single package: the
 * Composer repository that package is published in.
 *
 * Every event an endpoint can subscribe to is of this shape, so the answer is
 * the same three times over — and it is worth being the same, because getting
 * it wrong on one of them is the difference between an endpoint hearing about
 * its own repository and an endpoint hearing about everyone's.
 *
 * @property-read Package $package
 */
trait AboutOnePackage
{
    public function webhookRepository(): ?Repository
    {
        return $this->package->composerRepository;
    }
}
