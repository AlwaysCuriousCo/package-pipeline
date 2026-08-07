<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class SyncPackages extends Command
{
    protected $signature = 'packages:sync
        {name? : Only sync the package with this composer name or "owner/repo" path}';

    protected $description = 'Sync package versions from their GitHub repositories';

    public function handle(PackageSynchronizer $synchronizer): int
    {
        $name = $this->argument('name');

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
            ->get()
            ->filter(fn (Package $package): bool => $name === null
                || $package->name === $name
                || $this->matchesRepository($package, $name));

        if ($packages->isEmpty()) {
            $this->components->error('No matching packages found.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($packages as $package) {
            try {
                $synchronizer->sync($package);
                $this->components->info("{$package->name}: {$package->versions()->count()} versions");
            } catch (Throwable $exception) {
                $failures++;
                $this->components->error("{$package->name}: {$exception->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
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
