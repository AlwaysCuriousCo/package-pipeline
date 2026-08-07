<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class SyncPackages extends Command
{
    protected $signature = 'packages:sync
        {name? : Only sync the package with this composer name}';

    protected $description = 'Sync package versions from their GitHub repositories';

    public function handle(PackageSynchronizer $synchronizer): int
    {
        $packages = Package::query()
            ->when($this->argument('name'), fn ($query, string $name) => $query->where('name', $name))
            ->get();

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
}
