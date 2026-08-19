<?php

namespace App\Console\Commands;

use App\Exceptions\SyncInProgress;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Services\RebuildPackage;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuilds packages inline: every version re-imported from its source. The
 * console flavour of the panel's Rebuild action, for recovery scripts and
 * after-normalizer-change sweeps.
 */
class RebuildPackages extends Command
{
    protected $signature = 'package:rebuild
        {name? : Only rebuild the package with this composer name; all synced packages when omitted}
        {--repo= : The Composer repository path, when the name is served in more than one}';

    protected $description = 'Re-import every version of one or all packages from their sources';

    public function handle(RebuildPackage $rebuild): int
    {
        $packages = Package::query()
            // Uploaded artifacts have no source to rebuild from.
            ->whereNotNull('repository')
            ->when($this->argument('name'), fn ($query, string $name) => $query->where('name', $name))
            // Present but empty is the registry root; see Package::livingIn().
            ->when(
                $this->option('repo') !== null,
                fn ($query) => $query->livingIn((string) $this->option('repo')),
            )
            ->get();

        if ($packages->isEmpty()) {
            $this->components->error('No matching packages with a source to rebuild from.');

            return self::FAILURE;
        }

        $failures = [];
        $skipped = [];

        $this->withProgressBar($packages, function (Package $package) use ($rebuild, &$failures, &$skipped): void {
            try {
                // A rebuild prunes and re-imports every version, so running it
                // over a sync already in flight is the one thing the queued
                // path goes out of its way to prevent.
                SyncPackageJob::runExclusively($package, fn () => $rebuild->rebuild($package));
            } catch (SyncInProgress $exception) {
                $skipped[$package->name] = $exception->getMessage();
            } catch (Throwable $exception) {
                $failures[$package->name] = $exception->getMessage();
            }
        });

        $this->newLine(2);

        foreach ($failures as $name => $reason) {
            $this->components->twoColumnDetail($name, "failed: {$reason}");
        }

        // Named apart from the failures because the remedy is different: the
        // package is fine, and running the command again once the sync lands
        // rebuilds it.
        foreach ($skipped as $name => $reason) {
            $this->components->twoColumnDetail($name, "not rebuilt: {$reason}");
        }

        $rebuilt = $packages->count() - count($failures) - count($skipped);

        $this->components->info(sprintf(
            'Rebuilt %d package%s%s%s.',
            $rebuilt,
            $rebuilt === 1 ? '' : 's',
            $failures === [] ? '' : sprintf(', %d failed', count($failures)),
            $skipped === [] ? '' : sprintf(', %d skipped', count($skipped)),
        ));

        // A rebuild that stood aside is not a rebuild: the sync it stood aside
        // for re-imports only what moved, which is exactly what a rebuild is
        // for not trusting.
        return $failures === [] && $skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
