<?php

namespace App\Support;

use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Where this app is willing to make an outbound request to.
 *
 * Two features here dial an address this app did not choose. The addresses in a
 * mirrored `dist.url` are chosen by whoever published the package on the
 * upstream — which, for an upstream like packagist.org, is the general public.
 * A registry that fetched them unconditionally is a server-side request forgery
 * primitive with an anonymous trigger: `GET /dist/evil/pkg/<ref>.zip` on a
 * public mirroring repository, and this app dials whatever host, port and path
 * the attacker wrote into their package's metadata. The reply is never served
 * back — the sha1 will not match — but a blind GET to `169.254.169.254` or an
 * internal admin port is worth having on its own.
 *
 * An outgoing webhook endpoint is the same shape with a louder reply: the URL
 * is typed by whoever holds the permission to create one, and both the status
 * and the first two hundred characters of the receiver's body come back into
 * the panel. That is a port scanner with a readout attached, so it is judged
 * under the same rules and its own escape hatches — see EgressProfile.
 *
 * So: a destination nobody vouched for is default-denied to the public
 * internet, and the rules are applied to *addresses* rather than to names,
 * because a name is only ever a claim about an address.
 *
 * Three things make that hold up:
 *
 * 1. **Every hop.** This is installed as a client middleware, below Guzzle's
 *    redirect handling, so a redirect is re-judged as its own destination. An
 *    honest CDN answering `302 http://10.0.0.1/` is the same attack with one
 *    more step in it, and a first-request-only check does not see it.
 * 2. **The connection, not the lookup.** The validated addresses are pinned
 *    onto the request, so the socket goes to an address this class approved
 *    rather than to whatever the name resolves to a millisecond later. Without
 *    that, a DNS record with a one-second TTL answers publicly for the check
 *    and privately for the connection, and the check has proved nothing.
 * 3. **An escape hatch that is a decision.** Some self-hosted upstreams
 *    legitimately name an internal object store, and some deploy pipelines
 *    legitimately live on an internal network. Each profile's
 *    `allow_private` turns the address rules off and its `allowed_hosts`
 *    exempts named hosts one at a time; every one of them defaults to refusing,
 *    and they are set in the environment, so opening one is an act of the
 *    operator who deployed this app rather than of whoever can reach a form.
 *
 * What is exempt *without* any of that is the caller's to say, and the two
 * callers say different things. An upstream's own origin is exempt, because an
 * operator who typed `http://nexus.internal:8081` into the admin panel has
 * already said where this app may reach — see UpstreamClient, where the same
 * origin also decides whether the upstream's credential travels. A webhook
 * endpoint has no equivalent and vouches for nothing: the URL *is* the
 * attacker-chosen input there, so DeliverWebhook exempts nothing at all.
 *
 * @see docs/mirroring.md
 * @see docs/outgoing-webhooks.md
 */
final class EgressPolicy
{
    /**
     * Address ranges nothing may send this app to.
     *
     * Everything that is not a public unicast address, stated as CIDRs rather
     * than deferred to `FILTER_FLAG_NO_PRIV_RANGE`: that flag's idea of
     * "reserved" is a shorter list than this one — it misses carrier-grade NAT
     * and all of multicast — and a guard is worth being able to read.
     */
    private const DENIED_NETWORKS = [
        '0.0.0.0/8',          // "this network", and 0.0.0.0 itself, which many stacks route to loopback
        '10.0.0.0/8',         // RFC 1918
        '100.64.0.0/10',      // carrier-grade NAT, and a very common container/VPN range
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, which is where every cloud's instance metadata service lives
        '172.16.0.0/12',      // RFC 1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.168.0.0/16',     // RFC 1918
        '198.18.0.0/15',      // benchmarking
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including 255.255.255.255
        '::/128',             // unspecified
        '::1/128',            // loopback
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * Name suffixes that describe an internal network by convention.
     *
     * Redundant with the address rules whenever they resolve to what their
     * name suggests, and not redundant at all when they do not: `.internal` is
     * exactly the name somebody gives a host they never expected a stranger to
     * be able to ask about. A single-label host is refused for the same
     * reason — it can only mean something through a search domain.
     */
    private const DENIED_SUFFIXES = ['.internal', '.local', '.localhost', '.home.arpa', '.intranet'];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly EgressProfile $profile,
    ) {}

    /**
     * The policy one feature's destinations are judged under.
     *
     * A named constructor rather than a container binding per profile, because
     * the profile is the interesting half of what this object is and a caller
     * asking for "an EgressPolicy" has not said enough. The mirror's is bound
     * in AppServiceProvider, which is the one place a bare EgressPolicy is
     * injected.
     */
    public static function for(EgressProfile $profile): self
    {
        return new self(app(HostResolver::class), $profile);
    }

    /**
     * A Guzzle middleware that applies this policy to every request that goes
     * through it, including each redirect hop.
     *
     * @param  Closure(string): bool  $exempt  a URL the caller has already vouched for
     */
    public function middleware(Closure $exempt): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler, $exempt) {
            $url = (string) $request->getUri();

            if ($exempt($url)) {
                return $handler($request, $options);
            }

            $addresses = $this->addressesFor($url);

            if ($addresses !== [] && defined('CURLOPT_RESOLVE')) {
                // The one line that makes the check about the connection
                // rather than about a lookup. curl is told what this name
                // resolves to for this request, so the address it connects to
                // is one of the addresses judged above and re-resolution
                // cannot substitute another. Every validated address is given,
                // not just the first, so a host with a dead frontend still
                // fails over the way it would have.
                $options['curl'][CURLOPT_RESOLVE] = [$this->pin($url, $addresses)];
            }

            return $handler($request, $options);
        };
    }

    /**
     * The addresses this URL may be reached at.
     *
     * An empty list means "reachable, and not pinned" — the two ways an
     * operator can turn the address rules off, where there is no validated set
     * to hold the connection to.
     *
     * @return list<string>
     *
     * @throws EgressRefused
     */
    public function addressesFor(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new EgressRefused("Refusing to reach a URL that cannot be parsed: {$url}");
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new EgressRefused("Refusing to reach a URL that is not http or https: {$url}");
        }

        // Brackets are the URL syntax for a literal IPv6 host, not part of the
        // address; leaving them on would make every literal unparseable and so
        // — since an unparseable address cannot be judged — refused for the
        // wrong reason.
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            throw new EgressRefused("Refusing to reach a URL with no host: {$url}");
        }

        if (in_array($host, $this->profile->allowedHosts(), true)) {
            return [];
        }

        if ($this->profile->allowsPrivate()) {
            return [];
        }

        $this->assertNameIsRoutable($host);

        $literal = filter_var($host, FILTER_VALIDATE_IP);

        $addresses = is_string($literal) ? [$literal] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new EgressRefused("Refusing to reach a host that does not resolve: {$host}");
        }

        foreach ($addresses as $address) {
            // Every address, not the one that would be used: a name that
            // answers with one public and one private address is a rebinding
            // attempt wearing a hat, and there is no ordering guarantee that
            // would make picking one of them safe.
            $this->assertAddressIsPublic($address, $host);
        }

        return $addresses;
    }

    /**
     * @throws EgressRefused
     */
    private function assertNameIsRoutable(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        if (! str_contains($host, '.')) {
            throw new EgressRefused("Refusing to reach a host that is not a public name: {$host}");
        }

        foreach (self::DENIED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new EgressRefused("Refusing to reach a host that names an internal network: {$host}");
            }
        }
    }

    /**
     * @throws EgressRefused
     */
    private function assertAddressIsPublic(string $address, string $host): void
    {
        $packed = $this->packed($address);

        if ($packed === null) {
            throw new EgressRefused("Refusing to reach {$host}, which resolves to something that is not an address: {$address}");
        }

        foreach (self::DENIED_NETWORKS as $network) {
            if ($this->within($packed, $network)) {
                throw new EgressRefused("Refusing to reach {$host}, which resolves into {$network}: {$address}");
            }
        }
    }

    /**
     * An address in its binary form, with any IPv4 address wrapped inside an
     * IPv6 one unwrapped first.
     *
     * `::ffff:127.0.0.1` is loopback, and `2002:7f00:1::` is loopback reached
     * through 6to4. Judged as sixteen opaque bytes both would sail past every
     * IPv4 rule above, so the four bytes that decide the question are taken
     * out and judged as what they are.
     */
    private function packed(string $address): ?string
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) !== 16) {
            return $packed;
        }

        // ::ffff:0:0/96, IPv4-mapped, and 64:ff9b::/96, NAT64 — both carry the
        // address in the last four bytes.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            return substr($packed, 12, 4);
        }

        // 2002::/16, 6to4, which carries it in the two words after the prefix.
        if (str_starts_with($packed, "\x20\x02")) {
            return substr($packed, 2, 4);
        }

        return $packed;
    }

    private function within(string $packed, string $network): bool
    {
        [$address, $prefix] = explode('/', $network);

        $subject = inet_pton($address);

        if ($subject === false || strlen($subject) !== strlen($packed)) {
            return false;
        }

        $bytes = intdiv((int) $prefix, 8);
        $bits = (int) $prefix % 8;

        if ($bytes > 0 && strncmp($packed, $subject, $bytes) !== 0) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $bits) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($subject[$bytes]) & $mask);
    }

    /**
     * `host:port:address,address` — curl's own syntax for "this name is at
     * these addresses", with IPv6 bracketed as curl requires.
     *
     * @param  list<string>  $addresses
     */
    private function pin(string $url, array $addresses): string
    {
        $parts = parse_url($url);

        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443));

        $pinned = array_map(
            fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        );

        return "{$host}:{$port}:".implode(',', $pinned);
    }
}
