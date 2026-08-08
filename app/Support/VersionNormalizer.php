<?php

namespace App\Support;

use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * One spelling for every version this registry stores.
 *
 * Tags arrive as whatever a maintainer typed — v1.2.3, 1.0.0-beta.2 — and
 * are normalized once at import, so equality checks and metadata all agree.
 * Alongside the display version, order() produces a string whose *lexical*
 * order matches semantic version order, which is what lets the database sort
 * versions correctly (a plain string sort puts 1.10.0 below 1.9.0).
 */
class VersionNormalizer
{
    /**
     * Lexically ordered stability weights: a stable release outranks its own
     * release candidates, and a rare post-release patch outranks stable.
     *
     * @var array<string, string>
     */
    private const STABILITY_WEIGHTS = [
        'dev' => '1',
        'alpha' => '2',
        'beta' => '3',
        'rc' => '4',
        'stable' => '5',
        'patch' => '6',
        'pl' => '6',
        'p' => '6',
    ];

    public function __construct(private readonly VersionParser $parser = new VersionParser) {}

    /**
     * The version a tag publishes as: the leading "v" dropped and pre-release
     * suffixes tidied (1.0.0-beta.2 → 1.0.0-beta2). Dev versions pass through
     * untouched — they are branch names, not tags.
     */
    public function version(string $tag): string
    {
        $tag = trim($tag);

        if ($this->isDev($tag)) {
            return $tag;
        }

        $version = preg_replace('/^v/i', '', $tag) ?? $tag;

        return preg_replace('/[-._]?(alpha|beta|rc|patch|pl|p)[-._]?(\d*)$/i', '-$1$2', $version) ?? $version;
    }

    /**
     * The Composer dev version a branch publishes as, following Packagist's
     * naming: numeric branches become "2.x-dev" / "2.0.x-dev", version-like
     * ones keep their shape with "-dev" appended, and everything else is
     * "dev-{branch}".
     */
    public function devVersion(string $branch): string
    {
        if (preg_match('/^v?\d+(\.\d+)*$/', $branch)) {
            return "{$branch}.x-dev";
        }

        if (preg_match('/^v?\d+(\.(\d+|x))*$/i', $branch)) {
            return "{$branch}-dev";
        }

        return "dev-{$branch}";
    }

    /**
     * A string whose lexical order is the version's semantic order.
     *
     * Built from composer/semver's canonical four-segment normal form, with
     * each segment zero-padded and the stability suffix mapped to a weighted
     * tail — so `order by` in SQL ranks 1.10.0 above 1.9.0, and 1.0.0 above
     * its own RCs. Dev versions return as themselves: branches have no
     * position on a release line, and the endpoints sort them by name.
     */
    public function order(string $version): string
    {
        if ($this->isDev($version)) {
            return $version;
        }

        try {
            $normalized = $this->parser->normalize($version);
        } catch (UnexpectedValueException) {
            // Unparseable input sorts beneath every real version rather than
            // failing the import that carried it.
            return sprintf('%08d.%08d.%08d.%08d-0.0000', 0, 0, 0, 0);
        }

        preg_match(
            '/^(\d+)\.(\d+)\.(\d+)\.(\d+)(?:-(dev|alpha|beta|RC|patch|pl|p)(\d*))?$/i',
            $normalized,
            $matches,
        );

        $weight = self::STABILITY_WEIGHTS[strtolower($matches[5] ?? 'stable')] ?? '5';

        return sprintf(
            '%08d.%08d.%08d.%08d-%s.%04d',
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            (int) $matches[4],
            $weight,
            (int) ($matches[6] ?? 0),
        );
    }

    /**
     * Whether a version string names a branch rather than a release.
     */
    public function isDev(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }
}
