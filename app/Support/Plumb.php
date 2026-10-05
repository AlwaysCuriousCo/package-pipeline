<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What this registry keeps of a Plumb scan, and how it is shown.
 *
 * Plumb (plumbphp.dev) scores PHP packages on security, maintenance and
 * ecosystem health. It is a hosted service with a public JSON API and nothing
 * to install: `plumb:refresh` reads a package's scan history and stores one
 * summary per package and per scanned release, in the shape built here.
 *
 * @see docs/plumb.md
 */
final class Plumb
{
    public const API = 'https://plumbphp.dev/api/v1';

    private const CATEGORIES = ['composite', 'security', 'maintenance', 'ecosystem'];

    /**
     * Off unless the operator turned it on: a lookup tells a third party the
     * name of every Composer package this registry holds, private ones
     * included, and that is not a decision an upgrade gets to make.
     */
    public static function enabled(): bool
    {
        return (bool) config('registry.plumb.enabled');
    }

    /**
     * The package's own page on Plumb, which is where the individual checks
     * live — and the link Plumb's usage guidelines ask a displayed score to
     * carry.
     */
    public static function pageUrl(string $name): string
    {
        return 'https://plumbphp.dev/'.$name;
    }

    /**
     * One scan from Plumb's history, reduced to what is stored.
     *
     * Rebuilt key by key rather than stored as received: this is a third
     * party's response, and what lands in the database should be the four
     * numbers and two strings the panel reads and nothing else.
     *
     * @param  array<array-key, mixed>  $scan
     * @return array{version: ?string, scanned_at: ?string, scores: array<string, ?float>}
     */
    public static function summary(array $scan): array
    {
        $scores = [];

        foreach (self::CATEGORIES as $category) {
            $score = $scan['scores'][$category] ?? null;

            // Null is Plumb's own answer for a category it could not assess.
            $scores[$category] = is_numeric($score) ? (float) $score : null;
        }

        return [
            'version' => is_string($scan['reference_version'] ?? null) ? $scan['reference_version'] : null,
            'scanned_at' => is_string($scan['scanned_at'] ?? null) ? $scan['scanned_at'] : null,
            'scores' => $scores,
        ];
    }

    /**
     * The composite score as the panel prints it, or null when unscored.
     *
     * @param  array<string, mixed>|null  $summary
     */
    public static function score(?array $summary): ?int
    {
        $score = $summary['scores']['composite'] ?? null;

        return $score === null ? null : (int) round($score);
    }

    /**
     * A badge colour for a score.
     *
     * ponytail: bands read off the ratings plumbphp.dev prints beside its
     * scores (Good / Fair / Concerning / Poor), which its API does not
     * return — move these if Plumb publishes the thresholds.
     */
    public static function color(?int $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 86 => 'success',
            $score >= 70 => 'info',
            $score >= 50 => 'warning',
            default => 'danger',
        };
    }

    /**
     * The category scores and what they describe, as one line.
     *
     * @param  array<string, mixed>|null  $summary
     */
    public static function breakdown(?array $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $parts = [];

        foreach (array_slice(self::CATEGORIES, 1) as $category) {
            $score = $summary['scores'][$category] ?? null;

            $parts[] = ucfirst($category).' '.($score === null ? 'unscored' : (int) round($score));
        }

        $line = implode(' · ', $parts);

        if (filled($summary['version'] ?? null)) {
            $line .= ' — scanned against '.$summary['version'];
        }

        if (filled($summary['scanned_at'] ?? null)) {
            $line .= ' on '.Carbon::parse($summary['scanned_at'])->toFormattedDateString();
        }

        return $line.'. Powered by Plumb.';
    }
}
