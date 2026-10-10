<?php

namespace App\Sources;

/**
 * One project as a provider lists it — the identity needed to onboard it as
 * a package, whatever the provider calls these things internally.
 */
final readonly class Project
{
    public function __construct(
        public string $id,
        public string $fullName,
        public string $webUrl,
        // A copy of someone else's project, which is rarely what anybody
        // means to publish; only offered, never assumed.
        public bool $fork = false,
    ) {}
}
