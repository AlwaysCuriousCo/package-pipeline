<?php

namespace App\Enums;

use App\Models\Package;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a package's presentation deck comes from. Stated rather than inferred,
 * for the reason PageBodySource is: clearing a textarea should never be the
 * way to go back to the repository's file.
 */
enum PresentationSource: string implements HasLabel
{
    /** A markdown file in the repository, read at sync time. */
    case Repository = 'repository';

    /** Markdown pasted into the panel. Nothing is read from the repository. */
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Repository => 'A file in the repository',
            self::Custom => 'Markdown pasted here',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Repository => 'Looks for the file named below, or '.implode(', ', Package::PRESENTATION_FILES).' when none is named.',
            self::Custom => 'Nothing is read from the repository. The only option for a package published by artifact upload.',
        };
    }
}
