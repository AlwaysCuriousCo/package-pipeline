<?php

namespace App\Enums;

use App\Models\Package;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a package page's body comes from.
 *
 * Stated rather than inferred. The first version of this decided it by
 * looking at whether the panel's textarea was empty, which meant the rule
 * "content written here wins" had to be explained on the form, and clearing
 * the box to go back to the repository's README looked like deleting
 * something. It also left no way to say "publish the file at docs/registry.md"
 * without renaming a file in the repository.
 *
 * @see Package::pageContent()
 */
enum PageBodySource: string implements HasLabel
{
    /**
     * Whichever of Package::PAGE_FILES the repository has, in order — a
     * package-page.md if one was written for this, otherwise the README.
     */
    case Auto = 'auto';

    /** One named file, wherever it lives in the package's directory. */
    case File = 'file';

    /** Markdown written in the panel. Nothing is read from the repository. */
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => 'The repository\'s page file or README',
            self::File => 'A specific file in the repository',
            self::Custom => 'Content written here',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Auto => 'Looks for '.implode(', ', Package::PAGE_FILES).', in that order, and publishes the first one it finds.',
            self::File => 'For a repository whose registry page is not its README — docs/registry.md, say.',
            self::Custom => 'Nothing is read from the repository. The only option for a package published by artifact upload.',
        };
    }

    /**
     * Whether this reads anything from the repository at all — which decides
     * whether a sync spends provider requests on the page.
     */
    public function readsRepository(): bool
    {
        return $this !== self::Custom;
    }
}
