<?php

namespace App\Services;

/**
 * What one sync actually changed.
 *
 * A sync that finds nothing new and a sync that publishes a release are the
 * same call and, until now, indistinguishable afterwards. Webhook-driven syncs
 * run on every push, so telling them apart is what keeps a notification about a
 * release from being a notification about a typo fix on a branch.
 */
final readonly class SyncOutcome
{
    /**
     * @param  list<string>  $releases  tagged versions this sync added
     * @param  list<string>  $devVersions  dev (branch) versions this sync added
     * @param  list<string>  $removed  versions that no longer exist upstream
     * @param  bool  $initialImport  whether the package had no versions before
     * @param  int  $total  versions the package has now
     */
    public function __construct(
        public array $releases = [],
        public array $devVersions = [],
        public array $removed = [],
        public bool $initialImport = false,
        public int $total = 0,
    ) {}

    /**
     * Whether anything about the package's versions moved at all.
     */
    public function changed(): bool
    {
        return $this->releases !== [] || $this->devVersions !== [] || $this->removed !== [];
    }
}
