<?php

namespace App\Notifications\Concerns;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Models\Repository;

/**
 * What a notification whose subject is a single package can answer from the
 * package alone: the Composer repository it is published in, which is the
 * webhook scope, and the panel screen a reader wants next, which is the
 * package's own.
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

    /**
     * @return array{label: string, url: string}
     *
     * @see AnnouncedByMail
     */
    protected function mailAction(): array
    {
        return [
            'label' => 'View package',
            'url' => PackageResource::getUrl('view', ['record' => $this->package]),
        ];
    }
}
