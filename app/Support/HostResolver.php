<?php

namespace App\Support;

/**
 * What a hostname resolves to, as the resolver this process would use.
 *
 * An interface for one implementation, because the thing it stands in front of
 * is the network: a test that wants to know what happens when a dist host
 * resolves to 127.0.0.1 cannot arrange that in DNS, and a test that used real
 * DNS would be answering a different question every time it ran.
 */
interface HostResolver
{
    /**
     * Every address this host resolves to, or an empty list when it does not.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
