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
