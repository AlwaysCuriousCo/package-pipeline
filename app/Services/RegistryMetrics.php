<?php

namespace App\Services;

use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\OutgoingWebhook;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Upstream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The registry's state as Prometheus exposition text.
 *
 * Two rules shape everything here, and both are about the cost of being
 * scraped every fifteen seconds forever:
 *
 * 1. **Nothing counts the downloads table.** It is the fastest-growing table in
 *    the schema, and `downloads:prune` shortens it — so a count over it would
 *    be both expensive and a different number from the lifetime total anybody
 *    means. `packages.total_downloads` is the denormalized counter that exists
 *    precisely so nothing has to, and `downloads:recalculate` is what puts the
 *    two back in step.
 * 2. **No per-package series.** A gauge labelled with a package name would make
 *    this endpoint's cardinality a function of how big the registry is — the
 *    classic way to take a Prometheus server down with a well-meaning exporter
 *    — and would publish every private package's name to whoever can scrape.
 *    Counts and ages only; the panel and the CSV export are where a question
 *    about one package is answered.
 *
 * @see docs/metrics.md
 */
class RegistryMetrics
{
    /**
     * Prefixed on every series, so these never collide with another exporter's
     * on a shared Prometheus and so one `package_pipeline_` matches all of them.
     */
    private const PREFIX = 'package_pipeline_';

    /**
     * The whole exposition document.
     *
     * Cached as rendered text rather than as the numbers behind it: two
     * Prometheus replicas scraping the same instance is the ordinary
     * deployment, and the second one should cost a cache read. A cached scrape
     * is not a stale reading — Prometheus timestamps a sample when it collects
     * it, so what the TTL bounds is resolution, not correctness.
     */
    public function render(): string
    {
        $seconds = (int) config('registry.metrics.cache_seconds');

        if ($seconds <= 0) {
            return $this->collect();
        }

        return (string) cache()->remember('metrics:exposition', $seconds, $this->collect(...));
    }

    private function collect(): string
    {
        return implode("\n", [
            ...$this->up(),
            ...$this->totals(),
            ...$this->syncHealth(),
            ...$this->queue(),
            ...$this->webhooks(),
            ...$this->mirror(),
            // The format wants a trailing newline, which implode gives us by
            // ending on an empty element rather than by concatenating one on.
            '',
        ]);
    }

    /**
     * A constant 1, which is the conventional way to say "this exporter
     * answered" — it gives an alert something to be absent, and carries the
     * build's identity as labels rather than as a series nobody would graph.
     *
     * @return list<string>
     */
    private function up(): array
    {
        return $this->metric(
            'up',
            'gauge',
            'Always 1. Its absence is the signal.',
            [['value' => 1, 'labels' => ['version' => (string) config('app.version', 'dev')]]],
        );
    }

    /**
     * What the registry holds.
     *
     * @return list<string>
     */
    private function totals(): array
    {
        return [
            ...$this->gauge('repositories', 'Composer repositories served.', Repository::query()->count()),
            ...$this->gauge('packages', 'Packages published by this registry.', Package::query()->count()),
            ...$this->gauge('versions', 'Package versions served.', PackageVersion::query()->count()),
            ...$this->metric(
                'downloads_total',
                // A counter, not a gauge: it only goes up, and Prometheus's
                // rate() is the whole reason anybody would graph it.
                'counter',
                'Dist downloads served, from the denormalized per-package counters.',
                [['value' => (int) Package::query()->sum('total_downloads'), 'labels' => []]],
            ),
        ];
    }

    /**
     * Whether packages are still receiving releases — the question `/up` cannot
     * answer and the reason this endpoint exists.
     *
     * @return list<string>
     */
    private function syncHealth(): array
    {
        // Packages published by artifact upload have no source to sync from, so
        // they are not stale and never will be. Counting them would put a
        // permanent floor under every one of these numbers.
        $syncable = Package::query()->whereNotNull('repository');

        $newest = (clone $syncable)->max('last_synced_at');

        return [
            ...$this->gauge(
                'packages_failing',
                'Packages whose last sync recorded an error.',
                Package::query()->whereNotNull('sync_error')->count(),
            ),
            ...$this->gauge(
                'packages_never_synced',
                'Packages with a repository that have never synced successfully.',
                (clone $syncable)->whereNull('last_synced_at')->count(),
            ),
            ...$this->gauge(
                'packages_stale',
                'Packages not synced in the last 24 hours. The schedule syncs hourly, so anything here is behind.',
                (clone $syncable)
                    ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subDay()))
                    ->count(),
            ),
            // How long since *any* package synced, which is the one number that
            // catches a scheduler that has stopped — every per-package figure
            // above looks fine for an hour after that happens.
            ...$this->gauge(
                'last_sync_age_seconds',
                'Seconds since the most recent successful sync of any package.',
                $this->age($newest),
            ),
        ];
    }

    /**
     * Queue depth, when the depth is ours to know.
     *
     * Only the database driver keeps its jobs in a table this app can count. On
     * Redis or SQS the queue is somebody else's to report, and inventing a zero
     * would be worse than saying nothing: an alert on `== 0` would be silently
     * satisfied forever. So the series are simply absent, which is a state
     * Prometheus expresses and a fabricated number is not.
     *
     * @return list<string>
     */
    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return [];
        }

        $pending = DB::table('jobs');

        return [
            ...$this->gauge('queue_pending_jobs', 'Jobs waiting on the database queue.', (clone $pending)->count()),
            ...$this->gauge('queue_failed_jobs', 'Jobs that exhausted their retries and were recorded as failed.', DB::table('failed_jobs')->count()),
            // Depth alone cannot tell a busy worker from a dead one — a rebuild
            // legitimately queues hundreds. How long the oldest job has been
            // waiting can, and is the number to alert on.
            ...$this->gauge(
                'queue_oldest_pending_seconds',
                'How long the oldest waiting job has been queued. Zero when the queue is empty.',
                $this->age($this->oldestPendingJob()),
            ),
        ];
    }

    /**
     * Outgoing webhook endpoints that are not delivering.
     *
     * Deliveries swallow their own failures on purpose, so a broken endpoint is
     * invisible outside the panel; this is how it reaches an alert.
     *
     * @return list<string>
     */
    private function webhooks(): array
    {
        return $this->gauge(
            'outgoing_webhooks_failing',
            'Active outgoing webhook endpoints whose last delivery did not get through.',
            OutgoingWebhook::query()->where('active', true)->failing()->count(),
        );
    }

    /**
     * What the mirror is holding, and only when something is mirroring.
     *
     * An installation with no upstreams — which is every installation until an
     * operator adds one — publishes none of these rather than a row of zeroes
     * nobody has a use for.
     *
     * @return list<string>
     */
    private function mirror(): array
    {
        if (! Upstream::query()->where('enabled', true)->exists()) {
            return [];
        }

        return [
            ...$this->gauge('mirror_documents', 'Upstream metadata documents cached.', MirroredPackage::query()->count()),
            ...$this->gauge('mirror_archives', 'Upstream release archives cached on the dist disk.', MirroredArchive::query()->count()),
            // The only one of these that maps to a bill, and the reason
            // mirror:prune exists. Summed from the column the fetcher recorded
            // rather than by listing the disk, which is a paid API call per
            // thousand objects.
            ...$this->gauge(
                'mirror_archive_bytes',
                'Disk held by cached upstream archives. mirror:prune is what bounds this.',
                (int) MirroredArchive::query()->sum('size'),
            ),
        ];
    }

    /**
     * When the oldest job still waiting was queued.
     *
     * `available_at` rather than `created_at`: a released job carries a future
     * availability, and counting a deliberate backoff as queueing delay would
     * make every retry look like a stalled worker.
     */
    private function oldestPendingJob(): ?string
    {
        $available = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        return $available === null
            ? null
            : CarbonImmutable::createFromTimestamp((int) $available)->toDateTimeString();
    }

    /**
     * Seconds since a timestamp, or 0 when there is none.
     *
     * Zero and not "absent" because both readings this is used for have a
     * sensible floor: an empty queue really has waited no time, and a registry
     * that has never synced anything is caught by `packages_never_synced`
     * beside it. A gauge that vanished when things were fine would make every
     * alert on it need an `absent()` clause.
     */
    private function age(mixed $timestamp): int
    {
        if (blank($timestamp)) {
            return 0;
        }

        // Carbon 3 returns a float here, and letting PHP coerce that on the way
        // out is a deprecation notice per scrape — which at four scrapes a
        // minute is a log nobody can read anything else in.
        $seconds = now()->diffInSeconds(CarbonImmutable::parse((string) $timestamp), absolute: true);

        return max(0, (int) round($seconds));
    }

    /**
     * @return list<string>
     */
    private function gauge(string $name, string $help, int $value): array
    {
        return $this->metric($name, 'gauge', $help, [['value' => $value, 'labels' => []]]);
    }

    /**
     * One metric family: its HELP, its TYPE, and its samples.
     *
     * @param  list<array{value: int|float, labels: array<string, string>}>  $samples
     * @return list<string>
     */
    private function metric(string $name, string $type, string $help, array $samples): array
    {
        $family = self::PREFIX.$name;

        $lines = [
            "# HELP {$family} {$help}",
            "# TYPE {$family} {$type}",
        ];

        foreach ($samples as $sample) {
            $lines[] = $family.$this->labels($sample['labels']).' '.$sample['value'];
        }

        return $lines;
    }

    /**
     * Labels as the format wants them, escaped as the format requires:
     * backslash, double quote and newline, and nothing else.
     *
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);

            $pairs[] = "{$key}=\"{$escaped}\"";
        }

        return '{'.implode(',', $pairs).'}';
    }
}
