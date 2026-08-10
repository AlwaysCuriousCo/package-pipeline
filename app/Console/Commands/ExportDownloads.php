<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Services\DownloadExport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Download statistics as CSV, from the shell.
 *
 * The panel's action covers "I am looking at this and want it in a
 * spreadsheet"; this covers the two things a browser is bad at — a scheduled
 * extract into a warehouse or an object store, and an export large enough that
 * nobody wants it going through a web server at all.
 *
 * Unscoped, deliberately. There is no session here to narrow to, and a caller
 * holding a shell on the app already holds the database this reads. Every path
 * a *user* can reach passes a user; see DownloadExport.
 */
class ExportDownloads extends Command
{
    protected $signature = 'downloads:export
        {--package= : Only this package, by Composer name}
        {--repository= : The repository path the package is served from, when the name is ambiguous}
        {--from= : Include downloads on or after this date (YYYY-MM-DD)}
        {--to= : Include downloads on or before this date (YYYY-MM-DD)}
        {--detail : One row per download instead of one per version}
        {--path= : Write to this file instead of stdout}';

    protected $description = 'Export download statistics as CSV';

    public function handle(): int
    {
        try {
            $export = new DownloadExport(
                report: $this->option('detail') ? DownloadExport::DETAIL : DownloadExport::SUMMARY,
                from: $this->date('from'),
                to: $this->date('to'),
                package: $this->package(),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->path($export);

        // php://stdout rather than STDOUT so the two cases below are one piece
        // of code, and so `downloads:export | gzip` works exactly as an
        // operator would expect it to.
        $handle = fopen($path ?? 'php://stdout', 'w');

        if ($handle === false) {
            $this->components->error("Could not open [{$path}] for writing.");

            return self::FAILURE;
        }

        try {
            $rows = $export->writeTo($handle);
        } finally {
            fclose($handle);
        }

        // Only when the CSV is not itself on stdout, or the summary would be
        // the last line of the file somebody just piped somewhere.
        if ($path !== null) {
            $this->components->info("Wrote {$rows} rows to {$path}.");
        }

        return self::SUCCESS;
    }

    /**
     * Where to write, resolving a directory to the filename the export would
     * have been given in a browser — so `--path=storage/exports/` produces a
     * dated, named file rather than an error.
     */
    private function path(DownloadExport $export): ?string
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
     * A name that matches nothing is an error rather than an empty export: a
     * typo would otherwise produce a valid CSV with a header and no rows, which
     * reads exactly like a package nobody has downloaded.
     *
     * A name is only unique *within* a Composer repository, so two registries
     * served from one installation may each publish acme/widgets. Guessing
     * between them would silently export the wrong package's history, so an
     * ambiguous name is refused and --repository is what settles it.
     */
    private function package(): ?Package
    {
        $name = $this->option('package');

        if (! is_string($name) || $name === '') {
            return null;
        }

        $repository = $this->option('repository');

        $matches = Package::query()
            ->where('name', mb_strtolower($name))
            ->when(is_string($repository) && $repository !== '', fn ($query) => $query->whereHas(
                'composerRepository',
                fn ($repositories) => $repositories->where('path', $repository),
            ))
            ->with('composerRepository')
            ->get();

        throw_if($matches->isEmpty(), new RuntimeException(
            "No package is named \"{$name}\"".(is_string($repository) && $repository !== '' ? " in /r/{$repository}" : '')
                .'. Names are the Composer name, like "acme/widgets".',
        ));

        throw_if($matches->count() > 1, new RuntimeException(
            "\"{$name}\" is published by more than one Composer repository (".$matches
                ->map(fn (Package $package): string => $package->composerRepository->path ?? '(root)')
                ->implode(', ').'). Name one with --repository.',
        ));

        return $matches->first();
    }

    private function date(string $option): ?CarbonImmutable
    {
        $value = $this->option($option);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new RuntimeException("--{$option}=\"{$value}\" is not a date this can read; use YYYY-MM-DD.");
        }
    }
}
