<?php

namespace App\Enums;

use App\Models\Package;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a package's public page shows its own badges, and how.
 *
 * For the packages whose README does not embed them: the badges exist either
 * way (@see PackageBadgeController), this only decides whether the page
 * displays them itself.
 */
enum PageBadges: string implements HasLabel
{
    /** The README is the only place badges appear, if it embeds them. */
    case None = 'none';

    /** A row of badges in the page header, under the package name. */
    case Horizontal = 'horizontal';

    /** A card of stacked badges, floated beside the install and download section. */
    case Vertical = 'vertical';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No badges',
            self::Horizontal => 'Horizontal row',
            self::Vertical => 'Stacked card',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'Badges appear only if the README embeds them.',
            self::Horizontal => 'A row of badges in the header, under the package name.',
            self::Vertical => 'A card of stacked badges beside the install and download section.',
        };
    }
}
