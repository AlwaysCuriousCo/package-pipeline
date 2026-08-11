<?php

namespace App\Support;

/**
 * Composer's own package name grammar, in one place.
 *
 * There is exactly one right answer to "is this a package name", and it is not
 * a formatting preference — it is the guard several other things quietly rest
 * on. A name reaches a URL, a query, an outbound request and a path on the
 * dist disk, so a `vendor` of `..` is not a cosmetic problem. Three callers
 * were asking the question, two of them with patterns that had drifted apart
 * and one that had never asked at all.
 */
final class ComposerName
{
    /**
     * The grammar, as Composer's own ValidatingArrayLoader spells it.
     *
     * `D` is load-bearing rather than tidy. Without it `$` also matches before
     * a final newline, so `acme/private\n` would pass — and a route parameter
     * arrives rawurldecoded, which is how `%0A` gets into one. `u` makes the
     * refusal explicit for a subject that is not valid UTF-8, which preg_match
     * answers `false` for rather than `0`.
     *
     * The second group reads `(([_.]|-{1,2})?[a-z0-9]+)*` rather than the
     * `(([_.]?|-{0,2})[a-z0-9]+)*` the schema is usually quoted with. The two
     * accept the same names; this spelling has no alternative that can match
     * the empty string, which is what keeps a long near-miss from taking
     * exponential time to be refused.
     */
    public const PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/uD';

    /**
     * Whether this is a name Composer would agree to require.
     */
    public static function valid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * A Composer name pattern — a name with `*` standing for any run of
     * characters — as the expression that decides which names it covers.
     *
     * Composer's own BasePackage::packageNameToRegexp, deliberately: this
     * decides what `/list.json?filter=` answers, and a client that filters the
     * reply again with *its* rule would otherwise disagree with us about
     * `acme/widget-*`. Everything but `*` is quoted, so a filter of `.` means
     * a dot and a filter of `acme/{a,b}` matches nothing rather than throwing.
     *
     * `D` is the one addition, for the reason ComposerName::PATTERN carries it:
     * without it `$` also matches before a final newline, and this subject is
     * a query string parameter.
     */
    public static function patternToRegexp(string $pattern): string
    {
        return '{^'.str_replace('\*', '.*', preg_quote($pattern)).'$}iD';
    }
}
