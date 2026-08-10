<?php

namespace Tests\Unit;

use App\Services\Mirror\EgressPolicy;
use App\Services\Mirror\EgressRefused;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * Where a mirrored fetch is allowed to go.
 *
 * The addresses in a `dist.url` are written by whoever published the package
 * on the upstream, so on a repository mirroring packagist.org they are written
 * by the general public — and an anonymous `/dist/…` request is the trigger.
 * These are the destinations that has to fail to reach.
 */
class EgressPolicyTest extends TestCase
{
    private function policy(): EgressPolicy
    {
        return app(EgressPolicy::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedDestinations(): array
    {
        return [
            'the instance metadata service' => ['http://169.254.169.254/latest/meta-data/iam/'],
            'loopback' => ['http://127.0.0.1:8080/'],
            'loopback by any other name' => ['http://127.9.9.9/'],
            'IPv6 loopback' => ['http://[::1]/x.zip'],
            'IPv4 loopback wrapped in IPv6' => ['http://[::ffff:127.0.0.1]/x.zip'],
            'IPv4 loopback reached through 6to4' => ['http://[2002:7f00:1::]/x.zip'],
            'a private address behind NAT64' => ['http://[64:ff9b::a00:1]/x.zip'],
            'a unique local address' => ['http://[fd00::1]/x.zip'],
            'a link-local address' => ['http://[fe80::1]/x.zip'],
            'RFC 1918, ten' => ['http://10.1.2.3/x.zip'],
            'RFC 1918, one seven two' => ['http://172.16.0.1/x.zip'],
            'RFC 1918, one nine two' => ['http://192.168.1.1/x.zip'],
            'carrier-grade NAT' => ['http://100.64.1.1/x.zip'],
            'multicast' => ['http://224.0.0.1/x.zip'],
            'this network' => ['http://0.0.0.0/x.zip'],
            'a name that says it is internal' => ['https://objects.internal/x.zip'],
            'a name with no public part at all' => ['https://nexus/x.zip'],
            'localhost' => ['http://localhost/x.zip'],
            'a scheme that is not HTTP' => ['file:///etc/passwd'],
            'a scheme nothing here speaks' => ['gopher://cdn.example.com/x.zip'],
            'no host to speak of' => ['http:///x.zip'],
        ];
    }

    #[DataProvider('refusedDestinations')]
    public function test_a_destination_off_the_public_internet_is_refused(string $url): void
    {
        $this->expectException(EgressRefused::class);

        $this->policy()->addressesFor($url);
    }

    public function test_an_ordinary_public_destination_is_allowed(): void
    {
        $this->assertSame(
            [FakeHostResolver::DEFAULT_ADDRESS],
            $this->policy()->addressesFor('https://cdn.example.com/zipball/abc.zip'),
        );

        // A literal is judged as itself rather than resolved, which is the
        // same rules reaching the same answer by a shorter road.
        $this->assertSame(['198.51.100.7'], $this->policy()->addressesFor('http://198.51.100.7:8443/x.zip'));
    }

    public function test_a_name_that_answers_publicly_and_privately_is_refused(): void
    {
        $this->resolver()->answer('split.example.com', '198.51.100.7', '10.0.0.5');

        // Every address, not the one that happens to be first: nothing here
        // gets to choose which of them the connection would have used, and a
        // name answering both ways is a rebinding attempt written out longhand.
        $this->expectException(EgressRefused::class);

        $this->policy()->addressesFor('https://split.example.com/x.zip');
    }

    public function test_a_host_that_does_not_resolve_is_refused(): void
    {
        $this->resolver()->answer('nowhere.example.com');

        $this->expectException(EgressRefused::class);

        $this->policy()->addressesFor('https://nowhere.example.com/x.zip');
    }

    public function test_an_operator_can_name_one_internal_host_without_opening_the_rest(): void
    {
        config()->set('registry.mirror.egress.allowed_hosts', ['objects.internal']);

        // Nothing to pin: an exempted host was never judged on its addresses,
        // so there is no validated set to hold the connection to.
        $this->assertSame([], $this->policy()->addressesFor('https://objects.internal/x.zip'));

        $this->expectException(EgressRefused::class);

        $this->policy()->addressesFor('https://other.internal/x.zip');
    }

    public function test_an_operator_can_turn_the_address_rules_off_altogether(): void
    {
        config()->set('registry.mirror.egress.allow_private', true);

        $this->assertSame([], $this->policy()->addressesFor('http://10.0.0.5:9000/x.zip'));

        // The scheme is not part of the bargain: an escape hatch for an
        // internal object store is not a reason to fetch a local file.
        $this->expectException(EgressRefused::class);

        $this->policy()->addressesFor('file:///etc/passwd');
    }

    public function test_the_validated_addresses_are_pinned_onto_the_request(): void
    {
        $this->resolver()->answer('cdn.example.com', '198.51.100.7', '2001:db8::1');

        $options = [];

        $handler = ($this->policy()->middleware(fn (string $url): bool => false))(
            function (RequestInterface $request, array $sent) use (&$options): string {
                $options = $sent;

                return 'sent';
            },
        );

        $handler(new GuzzleRequest('GET', 'https://cdn.example.com/zipball/abc.zip'), []);

        // What curl is told this name resolves to, so the socket goes to an
        // address that was judged rather than to whatever the name says a
        // millisecond later. Both addresses, so a host with a dead frontend
        // still fails over the way it would have.
        $this->assertSame(
            ['cdn.example.com:443:198.51.100.7,[2001:db8::1]'],
            $options['curl'][CURLOPT_RESOLVE] ?? null,
        );
    }

    public function test_a_destination_the_caller_vouches_for_is_not_judged(): void
    {
        $reached = false;

        $handler = ($this->policy()->middleware(fn (string $url): bool => true))(
            function () use (&$reached): string {
                $reached = true;

                return 'sent';
            },
        );

        // An upstream an operator typed into the admin panel is allowed to be
        // `http://nexus.internal:8081`, which is the ordinary shape of a
        // self-hosted one. UpstreamClient decides that, on the same origin
        // comparison that decides whether the credential travels.
        $handler(new GuzzleRequest('GET', 'http://nexus.internal:8081/p2/acme/widgets.json'), []);

        $this->assertTrue($reached);
    }
}
