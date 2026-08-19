<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Removes a package, its versions, and the archives on the dist disk that
 * served them — the whole footprint, not just the rows.
 */
class DeletePackage extends Command
{
    protected $signature = 'package:delete
        {name : The composer name of the package}
        {--repo= : The Composer repository path, when the name is served in more than one}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a package, its versions, and its stored archives';

    public function handle(ArchiveStore $archives): int
    {
        $candidates = Package::query()
            ->where('name', $this->argument('name'))
            // Present but empty is the registry root; see Package::livingIn().
            ->when(
                $this->option('repo') !== null,
                fn ($query) => $query->livingIn((string) $this->option('repo')),
            )
            ->with('composerRepository')
            ->get();

        if ($candidates->isEmpty()) {
            $this->components->error('No matching package found.');

            return self::FAILURE;
        }

        if ($candidates->count() > 1) {
            $this->components->error(sprintf(
                'That name is served from several Composer repositories (%s); pick one with --repo.',
                $candidates->map(fn (Package $package): string => $package->composerRepository->path ?? '(root)')->implode(', '),
            ));

            return self::FAILURE;
        }

        /** @var Package $package */
        $package = $candidates->sole();
        $versions = $package->versions()->count();

        if (! $this->option('force')) {
            $confirmed = $this->input->isInteractive()
                && confirm("Delete {$package->name} and its {$versions} stored versions?", default: false);

            if (! $confirmed) {
                $this->components->warn('Nothing deleted. Pass --force to skip the confirmation.');

                return self::FAILURE;
            }
        }

        // Collected before the rows cascade away; deleted after, so a failed
        // delete never leaves versions pointing at missing files.
        $paths = $package->versions()->whereNotNull('archive_path')->pluck('archive_path');

        $package->delete();

        $disk = $archives->disk();

        foreach ($paths as $path) {
            $disk->delete($path);
        }

        $this->components->info(sprintf(
            'Deleted %s, %d version%s, and %d stored archive%s.',
            $package->name,
            $versions,
            $versions === 1 ? '' : 's',
            $paths->count(),
            $paths->count() === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
