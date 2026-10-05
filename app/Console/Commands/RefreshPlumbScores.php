<?php

namespace App\Console\Commands;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Support\HttpTimeouts;
use App\Support\Plumb;
use App\Support\VersionNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class RefreshPlumbScores extends Command
{
    protected $signature = 'plumb:refresh
        {name? : Only refresh the package with this composer name}';

    protected $description = 'Record Plumb\'s scores for each Composer package and each release it has scanned';

    /**
     * Plumb scores a package against its latest stable release, so there is no
     * asking it about a version directly. Its scan history is what makes the
     * scores per version anyway: every scan names the release it ran against,
     * so the newest scan of each release is that version's score, and the
     * newest scan of all is the package's.
     *
     * Composer packages only — Plumb knows nothing of the other ecosystems.
     * A package Plumb has never scanned (anything neither on Packagist nor
     * registered with Plumb by its owner) is left unscored, and a package it
     * has stopped answering for loses the score it had.
     */
    public function handle(VersionNormalizer $versions): int
    {
        if (! Plumb::enabled()) {
            $this->components->warn('Plumb scoring is off — set PLUMB_ENABLED=true to turn it on. See docs/plumb.md.');

            return self::SUCCESS;
        }

        $packages = Package::query()
            ->ofEcosystem(Ecosystem::Composer)
            ->when($this->argument('name'), fn (Builder $query, string $name) => $query->where('name', $name))
            ->lazyById();

        // One name can live in several repositories; Plumb is asked once.
        $histories = [];
        $failures = 0;

        foreach ($packages as $package) {
            try {
                $scans = $histories[$package->name] ??= $this->history($package->name);
            } catch (RequestException $exception) {
                if ($exception->response->status() === 429) {
                    $this->components->error('Plumb is rate limiting this address — stopping here; the next run picks up the rest.');

                    return self::FAILURE;
                }

                $this->components->warn("{$package->name}: {$exception->getMessage()}");
                $failures++;

                continue;
            } catch (ConnectionException $exception) {
                $this->components->warn("{$package->name}: {$exception->getMessage()}");
                $failures++;

                continue;
            }

            $this->record($package, $scans, $versions);

            $this->components->twoColumnDetail(
                $package->name,
                $scans === [] ? 'not scored' : (string) (Plumb::score($package->plumb) ?? 'unscored'),
            );
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Plumb's completed scans of a package, newest first; none when it has
     * never scanned the name.
     *
     * ponytail: the newest hundred scans only, and one package at a time
     * inside this process. Page through the history if older releases need
     * scores, and fan out to the queue if a registry outgrows a nightly loop.
     *
     * @return list<array<array-key, mixed>>
     */
    private function history(string $name): array
    {
        // Plumb allows 120 reads a minute per address; half a second between
        // requests cannot exceed that however fast it answers.
        Sleep::for(500)->milliseconds();

        $response = Http::baseUrl(Plumb::API)
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withUserAgent('package-pipeline')
            ->acceptJson()
            ->get("packages/{$name}/history", ['page[size]' => 100]);

        if ($response->notFound()) {
            return [];
        }

        return array_values(array_filter((array) $response->throw()->json('data'), is_array(...)));
    }

    /**
     * @param  list<array<array-key, mixed>>  $scans
     */
    private function record(Package $package, array $scans, VersionNormalizer $versions): void
    {
        // Bookkeeping, not content: a score moving must not move the /p2
        // fingerprint and send every client back for metadata that is the same.
        $package->recordBookkeeping(['plumb' => $scans === [] ? null : Plumb::summary($scans[0])]);

        $byVersion = [];

        foreach ($scans as $scan) {
            $summary = Plumb::summary($scan);

            if ($summary['version'] !== null) {
                // Plumb names the tag (v1.2.3); the registry stores 1.2.3.
                $byVersion[$versions->version($summary['version'])] ??= $summary;
            }
        }

        foreach ($byVersion as $version => $summary) {
            // Through the base builder for the same reason as above: Eloquent
            // would stamp updated_at, which is what /p2 is fingerprinted from.
            $package->versions()
                ->where('version', (string) $version)
                ->toBase()
                ->update(['plumb' => json_encode($summary)]);
        }
    }
}
