<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\Repository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Which Composer repositories serve a package.
 *
 * A package lives in one repository and can be served from any number of
 * others — the same row, the same versions and the same archives, answering
 * under another mount with its own access rules. This is that decision from a
 * provisioning script, where the panel's "Also served from" is the same one
 * from a screen.
 */
class ServePackage extends Command
{
    protected $signature = 'package:serve
        {name : The composer name of the package}
        {repo : The Composer repository path to serve it from; "root" or "" for the registry root}
        {--home= : The Composer repository path the package lives in, when the name is served in more than one}
        {--remove : Stop serving it there instead}';

    protected $description = 'Serve an existing package from another Composer repository';

    public function handle(): int
    {
        $package = $this->package();

        if (! $package instanceof Package) {
            return self::FAILURE;
        }

        $repository = $this->repository();

        if (! $repository instanceof Repository) {
            return self::FAILURE;
        }

        return $this->option('remove')
            ? $this->remove($package, $repository)
            : $this->add($package, $repository);
    }

    private function add(Package $package, Repository $repository): int
    {
        try {
            $package->serveFrom([$repository]);
        } catch (Throwable $exception) {
            // A name the target already answers for, or a vendor it may not
            // publish under — both already say what to do about it.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s is now served from %s. Consumers configure it with: %s',
            $package->name,
            $this->describe($repository),
            $repository->configureCommand(),
        ));

        return self::SUCCESS;
    }

    private function remove(Package $package, Repository $repository): int
    {
        if ($repository->getKey() === $package->repository_id) {
            $this->components->error(sprintf(
                '%s lives in %s. Move it to another repository instead of removing it from this one.',
                $package->name,
                $this->describe($repository),
            ));

            return self::FAILURE;
        }

        $package->stopServingFrom([$repository]);

        $this->components->info(sprintf(
            '%s is no longer served from %s. It is still served from %s.',
            $package->name,
            $this->describe($repository),
            Repository::query()
                ->whereKey($package->servingRepositoryIds())
                ->orderBy('name')
                ->pluck('name')
                ->implode(', '),
        ));

        return self::SUCCESS;
    }

    /**
     * The package this command addresses.
     *
     * A name is unique within a repository rather than across the registry, so
     * one served in two of them needs --home to say which is meant — the same
     * ambiguity package:delete resolves with --repo, and resolved the same way.
     */
    private function package(): ?Package
    {
        $candidates = Package::query()
            ->where('name', mb_strtolower((string) $this->argument('name')))
            // Present but empty is the registry root, exactly as the repo
            // argument reads it — so `!== null` rather than truthiness, which
            // would drop the filter for the most common home of all.
            ->when(
                $this->option('home') !== null,
                fn ($query) => $query->livingIn((string) $this->option('home')),
            )
            ->with('composerRepository')
            ->get();

        if ($candidates->isEmpty()) {
            $this->components->error('No matching package found.');

            return null;
        }

        if ($candidates->count() > 1) {
            $this->components->error(sprintf(
                'That name lives in several Composer repositories (%s); pick one with --home.',
                $candidates->map(fn (Package $package): string => $package->composerRepository->path ?? 'root')->implode(', '),
            ));

            return null;
        }

        return $candidates->sole();
    }

    /**
     * The repository named by the argument. The root repository is mounted at
     * no path at all, so it is named rather than pathed.
     */
    private function repository(): ?Repository
    {
        $path = trim((string) $this->argument('repo'));

        if ($path === '' || $path === 'root') {
            return Repository::default();
        }

        $repository = Repository::query()->where('path', $path)->first();

        if (! $repository instanceof Repository) {
            $this->components->error(sprintf(
                'No Composer repository is served at /r/%s. Existing paths: %s.',
                $path,
                Repository::query()->whereNotNull('path')->orderBy('path')->pluck('path')->implode(', ') ?: '(none)',
            ));

            return null;
        }

        return $repository;
    }

    private function describe(Repository $repository): string
    {
        return $repository->path === null
            ? "{$repository->name} (the registry root)"
            : "{$repository->name} (/r/{$repository->path})";
    }
}
