<?php

namespace App\Models;

use Database\Factories\PackageVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version', 'order', 'reference', 'is_dev', 'released_at', 'released_at_unknown', 'metadata', 'archive_path', 'shasum'])]
class PackageVersion extends Model
{
    /** @use HasFactory<PackageVersionFactory> */
    use HasFactory;

    /**
     * Composer's own word for "not an open source licence at all", which is
     * the one value the field is documented to take that SPDX does not define.
     *
     * @see https://getcomposer.org/doc/04-schema.md#license
     */
    public const PROPRIETARY = 'proprietary';

    protected static function booted(): void
    {
        // `license` is `metadata->license` folded to one expression, kept as a
        // column so the registry can be asked about its licensing without
        // decoding every manifest it stores. Derived here rather than at each
        // write, so the sync path, an artifact upload and a rebuild cannot
        // drift from one another.
        //
        // Only when the manifest is actually moving. A row read back with a
        // subset of its columns — which the synchronizer does for every stored
        // version on every sync — has no `metadata` attribute to derive from,
        // and recomputing would quietly clear the column on the next save of
        // it. `isDirty` is false in exactly that case and true for every
        // insert, which is the distinction wanted.
        static::saving(function (self $version): void {
            if ($version->isDirty('metadata')) {
                $version->license = self::licenseExpression($version->licenses());
            }
        });
    }

    /**
     * The declared licenses as the single SPDX expression the `license` column
     * holds, or null when nothing is declared.
     *
     * Several licenses become "A OR B" rather than being kept apart, because
     * that is what declaring several in a composer.json means: the consumer
     * may take the package under any one of them. It is also the one spelling
     * the licence report, the panel filter and the CycloneDX export all want,
     * so folding it once here keeps three readers from each doing it
     * differently.
     *
     * @param  list<string>  $licenses
     */
    public static function licenseExpression(array $licenses): ?string
    {
        $expression = implode(' OR ', array_unique($licenses));

        // The column is indexed and so is bounded; a declaration that will not
        // fit is not a licence anyone can act on anyway, and `metadata` still
        // holds whatever the manifest actually said.
        return $expression === '' ? null : mb_substr($expression, 0, 255);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_dev' => 'boolean',
            'released_at' => 'immutable_datetime',
            'released_at_unknown' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The licenses this version declares.
     *
     * Composer accepts either one SPDX identifier or a list of them, so both
     * spellings are answered as a list rather than left for every reader to
     * disambiguate.
     *
     * @return list<string>
     */
    public function licenses(): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $entry): string => is_scalar($entry) ? trim((string) $entry) : '',
                (array) ($this->metadata['license'] ?? []),
            ),
            fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * The declared authors, one readable line each.
     *
     * @return list<string>
     */
    public function authorLines(): array
    {
        $authors = $this->metadata['authors'] ?? [];

        if (! is_array($authors)) {
            return [];
        }

        $lines = array_map(function (mixed $author): string {
            if (! is_array($author)) {
                return is_scalar($author) ? trim((string) $author) : '';
            }

            $name = is_scalar($author['name'] ?? null) ? trim((string) $author['name']) : '';
            $email = is_scalar($author['email'] ?? null) ? trim((string) $author['email']) : '';

            return trim($name.($email === '' ? '' : " <{$email}>"));
        }, $authors);

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * A requirement block as "package: constraint" lines, in the order the
     * manifest declared them — which is the order a reader of the repository's
     * composer.json would see.
     *
     * @return list<string>
     */
    public function requirements(string $key = 'require'): array
    {
        $requirements = $this->metadata[$key] ?? [];

        if (! is_array($requirements)) {
            return [];
        }

        $lines = [];

        foreach ($requirements as $package => $constraint) {
            $lines[] = $package.': '.(is_scalar($constraint) ? (string) $constraint : '');
        }

        return $lines;
    }

    /**
     * Narrow to the versions of packages a caller may see.
     *
     * The packages query is passed in rather than a user or a token, so that
     * every reader of this reaches visibility through Package's own scopes —
     * the single chokepoint all access control here goes through — instead of
     * a second implementation of it growing beside them. A licence report and
     * an SBOM are both "everything visible, one row per version", and neither
     * is a place to be inventive about who may see what.
     *
     * @param  Builder<static>  $query
     * @param  Builder<Package>  $packages  already narrowed by visibleTo/visibleToUser
     * @return Builder<static>
     */
    public function scopeOfPackages(Builder $query, Builder $packages): Builder
    {
        return $query->whereIn('package_versions.package_id', $packages->select('packages.id'));
    }

    /**
     * Newest release first, spelled so that all three supported databases
     * agree on what "first" means.
     *
     * `order` is the normalizer's sortable spelling of the version, and is
     * what makes 1.10.0 outrank 1.9.0 — a plain sort on `version` is lexical
     * and puts them the other way round.
     *
     * It is nullable: a row synced before the column existed has none until
     * the next sync backfills it, and where a null sorts is not settled by
     * the standard. Postgres treats NULL as the largest value and so puts it
     * *first* on a descending sort; MySQL and SQLite treat it as the smallest
     * and put it last. The same registry served from Postgres would therefore
     * have floated its un-backfilled rows to the top of every metadata
     * document, where Composer reads the newest release — and the minifier
     * would have made that row the complete one every later version is
     * expressed as a diff against.
     *
     * So the placement is stated rather than inherited: unordered rows sort
     * last in either direction, which is what the other two databases already
     * did and what an unknown position deserves.
     *
     * The version is the tiebreak, because `order` is not unique — two
     * spellings of one release normalise to the same string — and a document
     * whose version sequence depends on the planner's whim would change its
     * bytes without anything about the package changing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderedByVersion(Builder $query, string $direction = 'desc'): Builder
    {
        // `order` is a reserved word, so the raw fragment borrows the
        // connection's own quoting rather than picking one dialect's.
        $order = $query->getQuery()->getGrammar()->wrap('order');

        return $query
            ->orderByRaw("case when {$order} is null then 1 else 0 end")
            ->orderBy('order', $direction)
            ->orderBy('version', $direction);
    }
}
