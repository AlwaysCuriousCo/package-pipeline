<?php

namespace App\Console\Commands;

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
            ->when($this->option('repo'), fn ($query, string $path) => $query->whereHas(
                'composerRepository',
                fn ($repositories) => $repositories->where('path', $path),
            ))
            ->get();

        if ($packages->isEmpty()) {
            $this->components->error('No matching packages with a source to rebuild from.');

            return self::FAILURE;
        }

        $failures = [];

        $this->withProgressBar($packages, function (Package $package) use ($rebuild, &$failures): void {
            try {
                $rebuild->rebuild($package);
            } catch (Throwable $exception) {
                $failures[$package->name] = $exception->getMessage();
            }
        });

        $this->newLine(2);

        foreach ($failures as $name => $reason) {
            $this->components->twoColumnDetail($name, "failed: {$reason}");
        }

        $this->components->info(sprintf(
            'Rebuilt %d package%s%s.',
            $packages->count() - count($failures),
            $packages->count() - count($failures) === 1 ? '' : 's',
            $failures === [] ? '' : sprintf(', %d failed', count($failures)),
        ));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
