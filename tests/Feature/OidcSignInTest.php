<?php

namespace Tests\Feature;

use App\Auth\OidcProvider;
use App\Auth\SsoProviderFactory;
use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The OIDC leg of SSO: endpoints read from the issuer's discovery document,
 * the authorization code traded for a token, and the userinfo claims mapped
 * onto an identity.
 *
 * SsoTest starts from an identity and asks what account it resolves to; this
 * starts a step earlier, where the identity is still an HTTP round trip.
 */
class OidcSignInTest extends TestCase
{
    use RefreshDatabase;

    private const DISCOVERY = 'https://idp.example/.well-known/openid-configuration';

    /**
     * The token requests Guzzle was asked to make, recorded by the handler
     * stack the provider is given.
     *
     * @var list<array{request: RequestInterface}>
     */
    private array $exchanges = [];

    private function source(): AuthenticationSource
    {
        return AuthenticationSource::factory()->create([
            'provider' => AuthProvider::Oidc,
            'discovery_url' => self::DISCOVERY,
            'client_id' => 'the-client',
            'client_secret' => 'the-secret',
        ]);
    }

    /**
     * An issuer answering discovery, and userinfo with the given claims.
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeIssuer(array $claims = []): void
    {
        Http::fake([
            self::DISCOVERY => Http::response([
                'authorization_endpoint' => 'https://idp.example/authorize',
                'token_endpoint' => 'https://idp.example/token',
                'userinfo_endpoint' => 'https://idp.example/userinfo',
            ]),
            'idp.example/userinfo' => Http::response($claims),
        ]);
    }

    /**
     * The browser coming back from the issuer with a code, and a session
     * holding the state the redirect leg put there.
     */
    private function returningWithCode(string $code = 'the-code', string $state = 'the-state'): void
    {
        $request = Request::create('/auth/callback', 'GET', ['code' => $code, 'state' => $state]);
        $request->setLaravelSession($this->app['session.store']);

        $this->app['session.store']->put('state', $state);
        $this->app->instance('request', $request);
    }

    /**
     * The provider, with the token exchange answered from memory. Socialite
     * trades the code over Guzzle rather than through the Http facade, so
     * that leg is stubbed at the handler and recorded for inspection.
     *
     * @param  array<string, mixed>  $token
     */
    private function provider(AuthenticationSource $source, array $token = ['access_token' => 'the-access-token']): OidcProvider
    {
        $provider = app(SsoProviderFactory::class)->provider($source);

        $this->assertInstanceOf(OidcProvider::class, $provider);

        $stack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode($token)),
        ]));

        $stack->push(Middleware::history($this->exchanges));

        return $provider->setHttpClient(new Client(['handler' => $stack]));
    }

    /**
     * The identity a full round trip produces. Socialite's contract only
     * promises the mapped fields, while the raw claims the controller reads
     * belong to the concrete user the provider builds.
     *
     * @param  array<string, mixed>  $token
     */
    private function identity(AuthenticationSource $source, array $token = ['access_token' => 'the-access-token']): SocialiteUser
    {
        $identity = $this->provider($source, $token)->user();

        if (! $identity instanceof SocialiteUser) {
            $this->fail('The OIDC provider returned something other than a mapped Socialite user.');
        }

        return $identity;
    }

    public function test_the_code_is_traded_at_the_issuers_token_endpoint(): void
    {
        $this->fakeIssuer(['sub' => 'ext-1']);
        $this->returningWithCode();

        $source = $this->source();

        $this->provider($source)->user();

        $this->assertCount(1, $this->exchanges);

        $request = $this->exchanges[0]['request'];

        $this->assertSame('https://idp.example/token', (string) $request->getUri());

        parse_str((string) $request->getBody(), $fields);

        $this->assertSame([
            'grant_type' => 'authorization_code',
            'client_id' => 'the-client',
            'client_secret' => 'the-secret',
            'code' => 'the-code',
            'redirect_uri' => $source->callbackUrl(),
        ], $fields);
    }

    public function test_the_claims_are_read_with_the_token_the_exchange_returned(): void
    {
        $this->fakeIssuer(['sub' => 'ext-1']);
        $this->returningWithCode();

        $this->provider($this->source(), ['access_token' => 'at-42'])->user();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://idp.example/userinfo'
            && $request->hasHeader('Authorization', 'Bearer at-42'));
    }

    public function test_the_standard_claims_become_the_identity(): void
    {
        $this->fakeIssuer([
            'sub' => 'ext-1',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $this->returningWithCode();

        $identity = $this->identity($this->source());

        $this->assertSame('ext-1', $identity->getId());
        $this->assertSame('Ada Lovelace', $identity->getName());
        $this->assertSame('ada@example.com', $identity->getEmail());
    }

    /**
     * `name` is optional in OIDC and plenty of issuers only send the shorter
     * claim; an account named after nobody is worse than one named after the
     * handle its owner already uses.
     */
    public function test_a_missing_name_falls_back_to_the_preferred_username(): void
    {
        $this->fakeIssuer([
            'sub' => 'ext-1',
            'preferred_username' => 'ada',
            'email' => 'ada@example.com',
        ]);
        $this->returningWithCode();

        $this->assertSame('ada', $this->identity($this->source())->getName());
    }

    /**
     * The controller reads email_verified straight off the raw claims to
     * decide whether an identity may adopt an existing account, so mapping
     * must not drop what it did not map.
     */
    public function test_the_raw_claims_survive_the_mapping(): void
    {
        $this->fakeIssuer([
            'sub' => 'ext-1',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'groups' => ['engineering'],
        ]);
        $this->returningWithCode();

        $raw = $this->identity($this->source())->getRaw();

        $this->assertTrue($raw['email_verified']);
        $this->assertSame(['engineering'], $raw['groups']);
    }

    public function test_an_issuer_that_names_no_endpoints_is_refused_rather_than_guessed_at(): void
    {
        Http::fake([self::DISCOVERY => Http::response(['authorization_endpoint' => 'https://idp.example/authorize'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token_endpoint');

        app(SsoProviderFactory::class)->provider($this->source());
    }

    /**
     * The redirect leg is where the discovery document first pays off: the
     * button sends the browser to whatever the issuer says its authorization
     * endpoint is, asking for the scopes the claims above come from.
     */
    public function test_the_redirect_leg_uses_the_issuers_authorization_endpoint(): void
    {
        $this->fakeIssuer();

        $source = $this->source();

        $location = (string) $this->get(route('sso.redirect', $source))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://idp.example/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('the-client', $query['client_id']);
        $this->assertSame($source->callbackUrl(), $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        // Space-separated, as OIDC spells a scope list.
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    /**
     * A callback that arrives without the state the redirect leg stored —
     * a stale tab, a bookmarked URL, or someone else's link — must land on
     * the login page with a reason rather than as an unhandled exception.
     */
    public function test_a_callback_with_no_state_is_a_refusal_and_not_a_500(): void
    {
        $source = AuthenticationSource::factory()->create();

        $this->get(route('sso.callback', $source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
    }

    public function test_a_provider_that_fails_outright_is_reported_the_same_way(): void
    {
        $source = AuthenticationSource::factory()->create();

        $this->mock(SsoProviderFactory::class)
            ->shouldReceive('provider')
            ->andReturn(new class implements Provider
            {
                public function redirect()
                {
                    return redirect('https://idp.example/authorize');
                }

                public function user()
                {
                    // Consent denied, the issuer down, the token endpoint
                    // answering 400 — all of it arrives here as a throw.
                    throw new RuntimeException('access_denied');
                }
            });

        $this->get(route('sso.callback', $source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
    }
}
