<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a package is to WordPress, when it is anything at all.
 *
 * Not an Ecosystem: a WordPress package still syncs from git and still serves
 * Composer, exactly as before. The kind adds a second surface on top — a zip
 * rooted at the slug, and the update API WordPress asks — and decides which
 * header file the sync reads: the main plugin file, or a theme's style.css.
 *
 * The values are the Composer `type`s the same packages conventionally
 * declare, so the panel and a composer.json read the same word.
 *
 * @see docs/wordpress.md
 */
enum WordPressKind: string implements HasLabel
{
    case Plugin = 'wordpress-plugin';
    case Theme = 'wordpress-theme';

    public function getLabel(): string
    {
        return match ($this) {
            self::Plugin => 'WordPress plugin',
            self::Theme => 'WordPress theme',
        };
    }
}
