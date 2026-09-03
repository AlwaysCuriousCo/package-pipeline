<?php

namespace App\Support;

/**
 * Python project names, shared by the index endpoints and the PyPI mirror.
 */
class PypiName
{
    /**
     * A project name as PEP 508 admits it, checked before normalization.
     */
    public const PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/';

    /**
     * The one spelling a project is stored and looked up under — PEP 503's:
     * lowercase, with runs of dots, hyphens and underscores folded to one
     * hyphen. pip normalizes before asking; this is the same fold for
     * hand-typed URLs and for whatever twine was handed.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[-_.]+/', '-', trim($name)));
    }

    public static function valid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
