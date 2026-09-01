<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which package manager's protocol a package is published under.
 *
 * One registry, one packages/package_versions schema, several protocol
 * surfaces: a package belongs to exactly one ecosystem, and each protocol's
 * endpoints only ever see their own. The name shapes keep the shared
 * (repository_id, name) namespace collision-free between the first two —
 * a Composer name is "vendor/name", an npm name is "@scope/name" or a bare
 * single segment, and NpmRegistryController refuses anything else.
 */
enum Ecosystem: string implements HasLabel
{
    case Composer = 'composer';
    case Npm = 'npm';
    case Pypi = 'pypi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
            self::Pypi => 'PyPI',
        };
    }
}
