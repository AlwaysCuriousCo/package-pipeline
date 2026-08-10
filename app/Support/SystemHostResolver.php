<?php

namespace App\Support;

/**
 * The system resolver, asked for both address families.
 *
 * `gethostbynamel` rather than a DNS query alone, because it goes through the
 * same resolver the HTTP client will: `/etc/hosts`, nsswitch, a container's
 * injected entries. A guard that consulted DNS directly would pass an address
 * that the connection then never uses, which is the entire failure mode it
 * exists to prevent.
 *
 * `dns_get_record` is then asked for AAAA, which `gethostbynamel` cannot
 * answer at all — an IPv6-only host would otherwise look unresolvable, and a
 * dual-stacked one would be judged on half of what it resolves to.
 */
final class SystemHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        // Silenced rather than checked: a host with no AAAA record makes the
        // underlying query fail on some resolvers and return an empty array on
        // others, and neither is an error here — it is one family of an answer
        // the other family may still have.
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
