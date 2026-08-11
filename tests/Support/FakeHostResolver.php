<?php

namespace Tests\Support;

use App\Support\HostResolver;

/**
 * DNS, as a test can arrange it.
 *
 * Every host answers with one ordinary public address unless a test says
 * otherwise, which is what keeps the suite's `upstream.test` and
 * `cdn.upstream.test` reachable without any test having to know that the
 * mirror resolves the hosts it fetches from. What a test does say otherwise
 * about is the interesting half: a CDN that answers 127.0.0.1, or a name that
 * answers one public address and one private one.
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * TEST-NET-3, the address block set aside for exactly this. Public as far
     * as the egress policy is concerned, and routable from nowhere.
     */
    public const DEFAULT_ADDRESS = '203.0.113.10';

    /** @var array<string, list<string>> */
    private array $answers = [];

    public function answer(string $host, string ...$addresses): self
    {
        $this->answers[mb_strtolower($host)] = array_values($addresses);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->answers[mb_strtolower($host)] ?? [self::DEFAULT_ADDRESS];
    }
}
