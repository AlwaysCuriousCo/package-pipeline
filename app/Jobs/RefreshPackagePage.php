<?php

namespace App\Jobs;

use App\Models\Package;
use App\Services\PackagePage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read a package's page markdown out of its repository, off the request.
 *
 * Dispatched when an admin switches a page on, because that is the moment
 * they expect to see one — and waiting for the next sync would show them an
 * empty page and no explanation. Off the request because this is up to five
 * calls to a provider's API, which is not something a form submission should
 * be holding a browser open for.
 *
 * A sync does the same work inline instead (PackageSynchronizer::finalize):
 * it is already talking to the provider, already knows which ref describes
 * the package, and has nothing to return to a waiting person.
 */
class RefreshPackagePage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * A page whose package was deleted before the job ran is not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Package $package) {}

    public function handle(PackagePage $pages): void
    {
        // The default branch rather than the release's ref: this runs outside
        // a sync, so there is nothing here that knows which ref described the
        // package, and the provider resolving its own default is the right
        // answer until the next sync replaces it with the release's.
        $pages->refresh($this->package);
    }
}
