<?php

namespace App\Services;

use App\Models\Package;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Download statistics as CSV.
 *
 * One implementation behind every way of asking for it — the panel's action and
 * the `downloads:export` command — because the only interesting parts of this
 * are the scoping and the streaming, and neither is worth having two of.
 *
 * **Nothing here materialises the downloads table.** It is the fastest-growing
 * table in the schema, is never pruned, and gains a row per zip the registry
 * serves; an export that loaded it would be an export that stopped working
 * exactly when the registry got popular. So rows are yielded one at a time from
 * a cursor, and the caller writes each one out as it arrives.
 *
 * @see docs/download-analytics.md
 */
class DownloadExport
{
    /**
     * Two reports, because two very different questions are asked of this data.
     *
     * A summary of ten million downloads is a few hundred rows somebody can
     * chart or paste into a spreadsheet; the detail is ten million rows, and is
     * what you want when the question is "who was pulling 1.2.0 last Tuesday".
     * Aggregating the detail afterwards is possible and is not the point — the
     * summary is one grouped query the database does, rather than ten million
     * rows crossing the wire to be counted in a spreadsheet.
     */
    public const SUMMARY = 'summary';

    public const DETAIL = 'detail';

    public function __construct(
        private readonly string $report = self::SUMMARY,
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
        /**
         * Narrow to one package, or to none for the whole registry.
         */
        private readonly ?Package $package = null,
        /**
         * Whose visibility to apply, or null for a caller that has none.
         *
         * Null is not "everything by default" for its own sake — it is the
         * console, which has no session to scope to and is already trusted with
         * the database it is reading. Every path a *user* can reach passes one.
         */
        private readonly ?User $user = null,
    ) {}

    /**
     * The filename a browser or an operator will end up with.
     *
     * Carries the report, the scope and the window, because the second thing
     * anybody does with an export is find it again in a downloads folder six
     * weeks later.
     */
    public function filename(): string
    {
        $scope = $this->package === null ? 'registry' : str_replace('/', '-', (string) $this->package->name);

        $window = $this->from === null && $this->to === null
            ? 'all-time'
            : ($this->from?->toDateString() ?? 'start').'_'.($this->to?->toDateString() ?? 'now');

        return "downloads-{$this->report}-{$scope}-{$window}.csv";
    }

    /**
     * The CSV, row by row, header first.
     *
     * A generator rather than a returned array, so that the caller — a
     * streaming HTTP response or a file handle — decides how much is ever in
     * memory at once, and so nothing is queried until somebody starts reading.
     *
     * @return Generator<int, list<string|int|null>>
     */
    public function rows(): Generator
    {
        return $this->report === self::DETAIL ? $this->detailRows() : $this->summaryRows();
    }

    /**
     * One row per package and version, with a count.
     *
     * Grouped in SQL, so what crosses the wire is the answer rather than the
     * evidence. Still yielded through a cursor: a registry with thousands of
     * versions produces thousands of rows, which is small but is not a reason
     * to hold them.
     *
     * @return Generator<int, list<string|int|null>>
     */
    private function summaryRows(): Generator
    {
        yield ['package', 'repository', 'version', 'downloads', 'first_download', 'last_download'];

        $query = $this->scoped()
            ->join('packages', 'packages.id', '=', 'downloads.package_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'packages.repository_id')
            ->groupBy('packages.name', 'repositories.path', 'downloads.version')
            ->orderBy('packages.name')
            ->orderBy('downloads.version')
            ->select([
                'packages.name as package',
                'repositories.path as repository',
                'downloads.version',
                DB::raw('count(*) as downloads'),
                DB::raw('min(downloads.created_at) as first_download'),
                DB::raw('max(downloads.created_at) as last_download'),
            ]);

        foreach ($query->cursor() as $row) {
            yield [
                $row->package,
                // The default repository is served at the registry root and has
                // no path; naming it beats an empty cell nobody can interpret.
                $row->repository ?? '(root)',
                $row->version,
                (int) $row->downloads,
                $this->timestamp($row->first_download),
                $this->timestamp($row->last_download),
            ];
        }
    }

    /**
     * One row per download.
     *
     * Ordered and streamed by primary key rather than by date: `created_at` is
     * indexed but is not unique, and a cursor over an unstable order can repeat
     * or skip rows while the table is being appended to — which it is, on every
     * request this registry serves. The id is monotonic with insertion time, so
     * this is also chronological.
     *
     * @return Generator<int, list<string|int|null>>
     */
    private function detailRows(): Generator
    {
        yield ['downloaded_at', 'package', 'repository', 'version', 'token_prefix'];

        $query = $this->scoped()
            ->join('packages', 'packages.id', '=', 'downloads.package_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'packages.repository_id')
            ->orderBy('downloads.id')
            ->select([
                'downloads.id',
                'downloads.created_at',
                'downloads.version',
                'downloads.token_prefix',
                'packages.name as package',
                'repositories.path as repository',
            ]);

        foreach ($query->cursor() as $row) {
            yield [
                $this->timestamp($row->created_at),
                $row->package,
                $row->repository ?? '(root)',
                $row->version,
                // A download served to an anonymous caller on a public
                // repository carries no credential, which is a fact rather
                // than a gap.
                $row->token_prefix ?? '(anonymous)',
            ];
        }
    }

    /**
     * The downloads this export may see, before either report shapes them.
     *
     * Visibility is applied as a subquery on `package_id` rather than by
     * joining the scope in: Package::scopeVisibleToUser composes `whereHas` and
     * `orWhereIn` against the `packages` table, and folding that into a query
     * that has already joined `packages` for its output columns would leave two
     * different reasons for the same table to be there.
     */
    private function scoped(): QueryBuilder
    {
        $query = DB::table('downloads');

        if ($this->package !== null) {
            $query->where('downloads.package_id', $this->package->getKey());
        }

        if ($this->user !== null) {
            $query->whereIn('downloads.package_id', $this->visiblePackageIds());
        }

        // Inclusive of both ends as an operator means them: "1st to 31st"
        // includes everything that happened on the 31st, so the window is
        // closed on the end of that day rather than on its midnight.
        if ($this->from !== null) {
            $query->where('downloads.created_at', '>=', $this->from->startOfDay());
        }

        if ($this->to !== null) {
            $query->where('downloads.created_at', '<=', $this->to->endOfDay());
        }

        return $query;
    }

    private function visiblePackageIds(): QueryBuilder
    {
        return Package::query()
            ->visibleToUser($this->user)
            ->select('packages.id')
            ->toBase();
    }

    /**
     * A timestamp in one spelling, whatever the driver handed back.
     *
     * SQLite and MySQL return a string here and PostgreSQL may return one in
     * its own format; an export that reflected that would be an export whose
     * date column parses differently depending on where the registry is
     * deployed. ISO 8601 with the offset, so a spreadsheet and a script both
     * read it the same way.
     */
    private function timestamp(mixed $value): ?string
    {
        return blank($value) ? null : CarbonImmutable::parse((string) $value)->toIso8601String();
    }

    /**
     * Write the whole CSV to an open handle, flushing as it goes.
     *
     * The one place rows become bytes, so the HTTP response and the file the
     * command writes cannot disagree about quoting or line endings. The handle
     * belongs to the caller: this neither opens nor closes it, because one
     * caller's handle is `php://output` mid-response and the other's is a file
     * that has to be closed even if this throws.
     *
     * @param  resource  $handle
     * @return int how many data rows were written, not counting the header
     */
    public function writeTo($handle): int
    {
        $written = -1;

        foreach ($this->rows() as $row) {
            fputcsv($handle, $row);

            $written++;
        }

        return $written;
    }

    /**
     * How many downloads are in scope — what a detail export would write, and
     * what a summary is counted from.
     *
     * A count over the `(package_id, created_at)` index rather than a fetch,
     * which is the one thing this table can be asked cheaply.
     */
    public function count(): int
    {
        return $this->scoped()->count();
    }
}
