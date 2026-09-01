<?php

namespace App\Support;

/**
 * What this registry accepts as an npm package name — shared by the npm
 * endpoints and the npm mirror, as ComposerName is by theirs.
 *
 * A bare lowercase segment or a scoped one, npm's own 214-character ceiling,
 * and deliberately disjoint from Composer's "vendor/name" shape: a slash only
 * ever follows an @scope, which is what lets the ecosystems share one
 * (repository_id, name) namespace without colliding.
 */
class NpmName
{
    public const PATTERN = '/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/';

    public static function valid(string $name): bool
    {
        return strlen($name) <= 214 && preg_match(self::PATTERN, $name) === 1;
    }
}
