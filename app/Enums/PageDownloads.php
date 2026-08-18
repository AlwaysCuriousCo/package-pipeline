<?php

namespace App\Enums;

use App\Models\Package;
use Filament\Support\Contracts\HasLabel;

/**
 * How much of a package's archive history its public page hands out.
 *
 * Three states rather than a boolean because the middle one is the point: a
 * page can offer the current release as a download without also publishing
 * every version that came before it, which is the difference between "here is
 * the thing" and "here is everything we have ever shipped".
 *
 * This decides what the *page* offers, never what the Composer endpoints do.
 * Those are governed by the repository's own public flag and the tokens
 * granted against it, and nothing here widens them — a page on a private
 * repository offers no archive whatever this says.
 *
 * @see Package::pageDownloads()
 */
enum PageDownloads: string implements HasLabel
{
    /** The page describes the package and links no archives at all. */
    case None = 'none';

    /** One button, for the latest release. */
    case Latest = 'latest';

    /** Every stored version, newest first. */
    case All = 'all';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No downloads',
            self::Latest => 'Latest release only',
            self::All => 'Every version',
        };
    }

    /**
     * The helper text the panel prints beside each choice, so the difference
     * between the two "yes" answers is decided in front of the admin rather
     * than discovered on the published page.
     */
    public function description(): string
    {
        return match ($this) {
            self::None => 'The page describes the package; visitors install it with Composer.',
            self::Latest => 'A download button for the current release.',
            self::All => 'A download link on every version in the history table.',
        };
    }

    public function offersAny(): bool
    {
        return $this !== self::None;
    }
}
