<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Services\SbomExport;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * A CycloneDX bill of materials, from the shell.
 *
 * The panel's button covers "I am looking at this and need it for an audit";
 * this covers the case that actually matters in a pipeline — a scheduled
 * upload into Dependency-Track, an artifact attached to a release, a diff
 * against last month's inventory in CI.
 *
 * Unscoped, deliberately, exactly as `downloads:export` is: there is no
 * session here to narrow to, and a caller holding a shell on the app already
 * holds the database this reads. Every path a *user* can reach passes a user;
 * see SbomExport.
 *
 * @see docs/licensing.md
 */
class ExportSbom extends Command
{
    protected $signature = 'sbom:export
        {--package= : Only this package, by Composer name}
        {--repository= : The repository path the package is served from, when the name is ambiguous}
        {--path= : Write to this file (or directory) instead of stdout}';

    protected $description = 'Export a CycloneDX software bill of materials';

    public function handle(): int
    {
        try {
            $export = new SbomExport(package: $this->package());
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->path($export);

        // php://stdout rather than STDOUT, so the two cases below are one
        // piece of code and `sbom:export | jq .` works as an operator expects.
        $handle = fopen($path ?? 'php://stdout', 'w');

        if ($handle === false) {
            $this->components->error("Could not open [{$path}] for writing.");

            return self::FAILURE;
        }

        try {
            $components = $export->writeTo($handle);
        } finally {
            fclose($handle);
        }

        // Only when the document is not itself on stdout, or this line would
        // be appended to the JSON somebody just piped into a tool.
        if ($path !== null) {
            $this->components->info("Wrote {$components} components to {$path}.");
        }

        return self::SUCCESS;
    }

    /**
     * Where to write, resolving a directory to the filename the export would
     * have been given in a browser.
     */
    private function path(SbomExport $export): ?string
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        return is_dir($path) ? rtrim($path, '/').'/'.$export->filename() : $path;
    }

    /**
     * The package named by --package, or null for the whole registry.
     *
     * A name that matches nothing is an error rather than an empty document: a
     * typo would otherwise produce a valid, well-formed bill of materials with
     * no components in it, which is the worst possible answer to "prove what
     * you ship".
     *
     * A name is only unique *within* a Composer repository, so an ambiguous
     * one is refused and --repository settles it.
     */
    private function package(): ?Package
    {
        $name = $this->option('package');

        if (! is_string($name) || $name === '') {
            return null;
        }

        $repository = $this->option('repository');
        $inRepository = is_string($repository) && $repository !== '';

        $matches = Package::query()
            ->where('name', mb_strtolower($name))
            ->when($inRepository, fn ($query) => $query->whereHas(
                'composerRepository',
                fn ($repositories) => $repositories->where('path', $repository),
            ))
            ->with('composerRepository')
            ->get();

        throw_if($matches->isEmpty(), new RuntimeException(
            "No package is named \"{$name}\"".($inRepository ? " in /r/{$repository}" : '')
                .'. Names are the Composer name, like "acme/widgets".',
        ));

        throw_if($matches->count() > 1, new RuntimeException(
            "\"{$name}\" is published by more than one Composer repository (".$matches
                ->map(fn (Package $package): string => $package->composerRepository->path ?? '(root)')
                ->implode(', ').'). Name one with --repository.',
        ));

        return $matches->first();
    }
}
