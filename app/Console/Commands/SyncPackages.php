<?php

namespace App\Console\Commands;

use App\Exceptions\SyncInProgress;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SyncPackages extends Command
{
    protected $signature = 'packages:sync
        {name? : Only sync the package with this composer name or "owner/repo" path}
        {--source= : Only sync packages connected through this source, by name or account}
        {--queue : Dispatch each sync to the queue instead of running it inline}';

    protected $description = 'Sync package versions from their GitHub repositories';

    public function handle(PackageSynchronizer $synchronizer): int
    {
        $name = $this->argument('name');
        $source = $this->option('source');

        // The stored name only becomes the composer name once a package has
        // synced, so an unsynced package is still addressable by its
        // repository. The LIKE narrows the scan; matchesRepository() below
        // makes the match exact.
        $packages = Package::query()
            ->when($name, fn ($query, string $name) => $query->where(
                fn ($query) => $query
                    ->where('name', $name)
                    ->orWhere('repository', 'like', '%'.$name.'%')
            ))
            ->when($source, fn ($query, string $source) => $query->whereHas(
                'source',
                fn ($query) => $query->where('name', $source)->orWhere('account', $source),
            ))
            ->get()
            ->filter(fn (Package $package): bool => $name === null
                || $package->name === $name
                || $this->matchesRepository($package, $name));

        if ($packages->isEmpty()) {
            $this->components->error('No matching packages found.');

            return self::FAILURE;
        }

        // Queued syncs report only that they were accepted; whether each one
        // succeeded is the worker's story to tell, on the package itself.
        if ($this->option('queue')) {
            foreach ($packages as $package) {
                SyncPackageJob::dispatch($package);
                $this->components->info("{$package->name}: queued");
            }

            return self::SUCCESS;
        }

        $failures = 0;
        $skipped = 0;

        foreach ($packages as $package) {
            try {
                // Inline, but never beside a sync the queue is already running:
                // two writers over one package's versions leave whichever
                // prunes last in charge of what survives.
                SyncPackageJob::runExclusively($package, fn () => $synchronizer->sync($package));

                $this->components->info("{$package->name}: {$package->versions()->count()} versions");
            } catch (SyncInProgress $exception) {
                $skipped++;
                $this->components->warn("{$package->name}: not synced — {$exception->getMessage()}.");
            } catch (Throwable $exception) {
                $failures++;
                $this->components->error("{$package->name}: {$exception->getMessage()}");
            }
        }

        // A skip is nobody's fault, but it is still a package this run was
        // asked to sync and did not: a script that chains on the exit code has
        // to be able to tell that apart from a sync that happened.
        return $failures === 0 && $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether the package's repository resolves to the given "owner/repo" path.
     */
    private function matchesRepository(Package $package, string $name): bool
    {
        try {
            return strcasecmp($package->repositoryPath(), $name) === 0;
        } catch (InvalidArgumentException) {
            // An unparseable repository simply doesn't match.
            return false;
        }
    }
}
