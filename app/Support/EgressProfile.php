<?php

namespace App\Support;

/**
 * Which set of escape hatches an egress decision is judged under.
 *
 * Two features in this app dial an address somebody else chose, and the rules
 * about where they may go are the same rules — but the decision to open them up
 * is not one decision. An installation whose self-hosted upstream lives on an
 * internal object store has said nothing about whether a panel user may point a
 * webhook at 169.254.169.254, and the reverse holds just as firmly.
 *
 * Each case names the config subtree holding that feature's `allow_private` and
 * `allowed_hosts`, so the two are separately deniable and separately opened.
 *
 * @see EgressPolicy
 */
enum EgressProfile: string
{
    /**
     * A `dist.url` or a metadata URL written by whoever published a package on
     * an upstream — the general public, on a repository mirroring packagist.org.
     */
    case Mirror = 'registry.mirror.egress';

    /**
     * An outgoing webhook endpoint, typed into the admin panel by whoever holds
     * the permission to create one. Which is not the same person as the
     * operator who deployed this app, and is the whole reason this is a
     * separate profile rather than the mirror's.
     */
    case Webhook = 'registry.webhooks.egress';

    public function allowsPrivate(): bool
    {
        return (bool) config($this->value.'.allow_private');
    }

    /**
     * The hosts this profile exempts by name, lowercased for comparison.
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = config($this->value.'.allowed_hosts', []);

        return array_map(mb_strtolower(...), $hosts);
    }
}
