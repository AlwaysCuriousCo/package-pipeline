<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What the GitHub App's own webhook is doing, as GitHub reports it.
 *
 * Distinct from `WebhookCoverage`, which is about one package. This is about
 * the single webhook on the app, and it exists because "the secret is set in
 * the environment" and "GitHub delivers here" are two different claims that
 * used to be treated as one.
 */
enum WebhookState: string implements HasColor, HasLabel
{
    /** GitHub confirms the app posts to this registry. */
    case Delivering = 'delivering';

    /** The app has no webhook: it was registered with Active unchecked. */
    case Absent = 'absent';

    /** The app has a webhook, but it posts somewhere else — another environment. */
    case Elsewhere = 'elsewhere';

    /** No secret here, so a delivery could not be trusted even if it arrived. */
    case NoSecret = 'no-secret';

    /** Nothing to ask GitHub with, or GitHub could not be reached. */
    case Unverifiable = 'unverifiable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Delivering => 'Delivering here',
            self::Absent => 'Not switched on',
            self::Elsewhere => 'Pointing elsewhere',
            self::NoSecret => 'No secret set',
            self::Unverifiable => 'Configured, unverified',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Delivering => 'success',
            self::Absent, self::NoSecret => 'warning',
            self::Elsewhere => 'danger',
            self::Unverifiable => 'gray',
        };
    }

    /**
     * What to do about it, or null when there is nothing to do.
     */
    public function remedy(string $url): ?string
    {
        return match ($this) {
            self::Delivering => null,
            self::Absent => 'The GitHub App has no webhook. On the app\'s settings page, tick Webhook → Active, set the payload URL below with content type application/json, paste the secret, '
                .'and subscribe it to Push, Branch or tag creation, and Branch or tag deletion. Until then, packages get a webhook on their own repository instead.',
            self::Elsewhere => "The app's webhook posts to {$url}, which is not this registry — another environment is using the same app. "
                .'Register a separate app for this one, or accept that its packages each carry their own repository webhook.',
            self::NoSecret => 'Set GITHUB_APP_WEBHOOK_SECRET to the secret on the app\'s webhook. Without it a delivery cannot be told from a forgery, so none are accepted.',
            self::Unverifiable => 'A secret is set, but GitHub could not confirm the app\'s webhook just now. It is taken at its word until it can be checked again.',
        };
    }
}
