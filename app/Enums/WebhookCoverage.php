<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a package hears about its own pushes, and through what.
 *
 * Auto-sync is only as good as the delivery path behind it, and there are two
 * of those with very different failure modes — so the state is named rather
 * than inferred from a nullable column at each call site.
 */
enum WebhookCoverage: string implements HasColor, HasLabel
{
    /** Covered account-wide by the GitHub App's own webhook. Nothing to create. */
    case Application = 'application';

    /** Covered by a hook created on the repository itself. */
    case Repository = 'repository';

    /** The app would cover it, but no webhook secret is configured here. */
    case Unconfigured = 'unconfigured';

    /** Creating the repository hook was attempted and failed. */
    case Failed = 'failed';

    /** No delivery path: pushes will not sync until one is set up. */
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Application => 'GitHub App webhook',
            self::Repository => 'Repository webhook',
            self::Unconfigured => 'Not configured',
            self::Failed => 'Not created',
            self::None => 'None',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Application, self::Repository => 'success',
            self::Failed => 'danger',
            self::Unconfigured, self::None => 'warning',
        };
    }

    /**
     * Whether pushes to this package's repository reach the app at all.
     */
    public function isActive(): bool
    {
        return $this === self::Application || $this === self::Repository;
    }
}
