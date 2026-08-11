<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What an outgoing webhook can be told about.
 *
 * These are the events an operator would wire something to: a release landing
 * (deploy it, tell a channel), a package that has stopped receiving releases
 * (page somebody), and a package that should stop being depended on (open a
 * ticket). Not every notification this app sends is here, and that is the
 * point — an endpoint subscribes to facts about the registry, not to the
 * panel's bell.
 *
 * The value is the wire format: it travels in the `X-Package-Pipeline-Event`
 * header and in the payload's `event` key, and a receiver switches on it. Adding
 * a case is additive; renaming one breaks every consumer, so treat these as
 * published API.
 *
 * @see docs/outgoing-webhooks.md
 */
enum WebhookEvent: string implements HasDescription, HasLabel
{
    /** A sync imported one or more new tagged versions. */
    case VersionPublished = 'version.published';

    /** A package's sync gave up after exhausting its retries. */
    case SyncFailed = 'sync.failed';

    /** A package was marked abandoned, with or without a replacement. */
    case PackageAbandoned = 'package.abandoned';

    /**
     * Sent only by the panel's "Send test delivery" button, to prove the URL,
     * the secret and the receiver all work before a real release depends on it.
     *
     * Not subscribable, exactly as GitHub's own `ping` is not: it is addressed
     * to one endpoint on purpose, so a subscription would be a way to *miss* it.
     */
    case Ping = 'ping';

    public function getLabel(): string
    {
        return match ($this) {
            self::VersionPublished => 'Version published',
            self::SyncFailed => 'Sync failed',
            self::PackageAbandoned => 'Package abandoned',
            self::Ping => 'Test delivery',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::VersionPublished => 'A sync brought in one or more new tagged releases. Dev branches moving do not fire this.',
            self::SyncFailed => 'A package stopped syncing and will not receive releases until it is fixed.',
            self::PackageAbandoned => 'A package was marked abandoned; consumers are being told to stop using it.',
            self::Ping => 'Sent by hand from the panel, never by the registry itself.',
        };
    }

    /**
     * The events an endpoint may subscribe to — everything a receiver could be
     * waiting for, which is every case except the one only ever addressed to a
     * single endpoint.
     *
     * @return list<self>
     */
    public static function subscribable(): array
    {
        return array_filter(self::cases(), fn (self $event): bool => $event !== self::Ping);
    }
}
