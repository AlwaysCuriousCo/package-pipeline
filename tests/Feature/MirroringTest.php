<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Upstream mirroring: serving packages this registry does not publish out of
 * another Composer repository, cached here.
 *
 * Http::preventStrayRequests() is on for every test in the suite, so "makes no
 * network call" needs no mock to assert — an unstubbed request fails the test
 * by itself, and a test that stubs nothing is a test that proves nothing was
 * fetched.
 */
class MirroringTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM = 'https://upstream.test';

    private const REFERENCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ZIP = 'PK-pretend-this-is-a-zip';

    /**
     * The default repository, mirroring one upstream.
     */
    private function mirroring(): Repository
    {
        $repository = Repository::default();

        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'name' => 'packagist.org',
            'url' => self::UPSTREAM,
        ]);

        return $repository->refresh();
    }

    /**
     * Stub the upstream's root document, plus whatever the test needs. Given
     * first so a test can override the root itself.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeUpstream(array $routes = []): void
    {
        Http::fake([
            ...$routes,
            'upstream.test/packages.json' => Http::response([
                'metadata-url' => '/p2/%package%.json',
                'security-advisories' => ['metadata' => false, 'api-url' => self::UPSTREAM.'/security-advisories'],
            ]),
        ]);
    }

    /**
     * A `/p2` document in the shape packagist.org serves one.
     *
     * @return array<string, mixed>
     */
    private function upstreamDocument(string $name = 'symfony/console', ?string $shasum = null): array
    {
        return [
            'minified' => 'composer/2.0',
            'packages' => [
                $name => [[
                    'name' => $name,
                    'version' => 'v6.0.0',
                    'version_normalized' => '6.0.0.0',
                    'type' => 'library',
                    'require' => ['php' => '>=8.1'],
                    'dist' => [
                        'type' => 'zip',
                        // Pointedly not this registry: rewriting it is the
                        // whole of what makes the archive cacheable here.
                        'url' => 'https://cdn.upstream.test/zipball/'.self::REFERENCE,
                        'reference' => self::REFERENCE,
                        'shasum' => $shasum ?? sha1(self::ZIP),
                    ],
                ]],
            ],
        ];
    }

    /**
     * The version objects a metadata response carries, expanded.
     *
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function versionsOf(TestResponse $response, string $name = 'symfony/console'): array
    {
        /** @var array<string, list<array<string, mixed>>> $packages */
        $packages = $response->json('packages');

        return MetadataMinifier::expand($packages[$name] ?? []);
    }

    private function makeLocalPackage(string $name, ?Repository $repository = null): Package
    {
        $package = Package::factory()->create([
            'name' => $name,
            'repository_id' => ($repository ?? Repository::default())->getKey(),
        ]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => sha1($name),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    public function test_a_repository_without_upstreams_behaves_exactly_as_before(): void
    {
        $this->makeLocalPackage('acme/widgets');

        $this->getJson('/packages.json')
            ->assertOk()
            ->assertJsonPath('available-package-patterns', ['acme/*']);

        // No stub, so any upstream request at all would fail this outright.
        $this->getJson('/p2/symfony/console.json')->assertNotFound();
    }

    public function test_a_mirroring_repository_tells_composer_it_may_answer_for_anything(): void
    {
        $this->mirroring();
        $this->makeLocalPackage('acme/widgets');

        // The local vendor list would have told Composer never to ask about
        // symfony/*, which is every package mirroring exists to serve.
        $this->getJson('/packages.json')
            ->assertOk()
            ->assertJsonPath('available-package-patterns', ['*/*']);
    }

    public function test_a_mirrored_package_resolves_and_is_then_served_from_cache(): void
    {
        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
        ]);

        $versions = $this->versionsOf($this->getJson('/p2/symfony/console.json')->assertOk());

        $this->assertSame('symfony/console', $versions[0]['name']);
        $this->assertSame('v6.0.0', $versions[0]['version']);

        // Pointed back here, carrying the upstream's own sha1 — which is what
        // the dist endpoint will check the bytes against before serving them.
        $this->assertSame(url('/dist/symfony/console/'.self::REFERENCE.'.zip'), $versions[0]['dist']['url']);
        $this->assertSame(sha1(self::ZIP), $versions[0]['dist']['shasum']);

        $this->assertDatabaseHas('mirrored_packages', ['name' => 'symfony/console', 'is_dev' => false]);

        // One root discovery and one metadata fetch, and then never again
        // inside the TTL: the second request is answered entirely from here.
        Http::assertSentCount(2);

        $this->getJson('/p2/symfony/console.json')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_branches_are_mirrored_as_their_own_document(): void
    {
        $this->mirroring();

        $document = $this->upstreamDocument();
        $document['packages']['symfony/console'][0]['version'] = 'dev-main';

        $this->fakeUpstream([
            // Composer asks for releases and branches separately, and an
            // upstream may have one and not the other — so they are cached and
            // revalidated as two independent documents, keyed by the plain
            // name the upstream answers under.
            'upstream.test/p2/symfony/console~dev.json' => Http::response($document),
        ]);

        $versions = $this->versionsOf($this->getJson('/p2/symfony/console~dev.json')->assertOk());

        $this->assertSame('dev-main', $versions[0]['version']);
        $this->assertDatabaseHas('mirrored_packages', ['name' => 'symfony/console', 'is_dev' => true]);
    }

    public function test_an_upstream_that_does_not_cover_a_name_is_not_reported_as_covering_it(): void
    {
        $this->mirroring();

        $this->fakeUpstream([
            'upstream.test/security-advisories' => Http::response(['advisories' => []]),
        ]);

        // Absent, not empty. An empty list is how Composer is told a
        // repository covers a name and found nothing, which would stop it
        // looking anywhere else for a package nobody here vouched for.
        $advisories = (array) $this->postJson('/security-advisories', ['packages' => ['symfony/console']])
            ->assertOk()
            ->json('advisories');

        $this->assertSame([], $advisories);

        $this->postJson('/security-advisories', ['packages' => ['symfony/console']])->assertOk();

        // And the answer is remembered, so an audit per build does not become
        // an upstream request per build.
        Http::assertSentCount(2);
    }

    public function test_a_local_package_always_wins_over_an_upstream(): void
    {
        $this->mirroring();
        $this->makeLocalPackage('symfony/console');

        // Nothing is stubbed. If the local package did not win unconditionally
        // this would reach for the upstream and fail as a stray request.
        $versions = $this->versionsOf($this->getJson('/p2/symfony/console.json')->assertOk());

        $this->assertSame('v1.0.0', $versions[0]['version']);
    }

    public function test_a_name_published_anywhere_in_this_installation_is_never_mirrored(): void
    {
        $this->mirroring();

        // Published in another repository, so the mount being asked cannot
        // serve it — and must not answer with somebody else's package of the
        // same name either. A 404 is the correct answer to "give me the
        // acme/secret you do not have".
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $this->makeLocalPackage('acme/secret', $internal);

        $this->getJson('/p2/acme/secret.json')->assertNotFound();
    }

    public function test_a_published_name_stored_in_mixed_case_still_beats_an_upstream(): void
    {
        $this->mirroring();

        // Package::normalizeName() lowercases on the way in, so this row can
        // only be one written around the model — a registry that predates that
        // hook, a `saveQuietly()`, a backfill. The guard is deliberately not
        // allowed to assume otherwise: Composer asks in lowercase, and on
        // SQLite or PostgreSQL an equality against the column would miss such a
        // row entirely and hand the name to packagist.org, where an attacker
        // would have put one.
        //
        // Published in another repository, so the mount being asked has no
        // local answer of its own and genuinely reaches the mirror.
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $package = $this->makeLocalPackage('acme/secret', $internal);

        DB::table('packages')->where('id', $package->getKey())->update(['name' => 'Acme/Secret']);

        $this->getJson('/p2/acme/secret.json')->assertNotFound();
        $this->get('/dist/acme/secret/'.self::REFERENCE.'.zip')->assertNotFound();

        $this->postJson('/security-advisories', ['packages' => ['acme/secret']])->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decoratedNames(): array
    {
        return [
            'trailing newline' => ['acme/private%0A'],
            'trailing carriage return' => ['acme/private%0D'],
            'trailing tab' => ['acme/private%09'],
            'leading space' => ['%20acme/private'],
            // Cyrillic а, U+0430. Indistinguishable from the published name in
            // every terminal, and a different string in every comparison.
            'Cyrillic homoglyph' => ['%D0%B0cme/private'],
        ];
    }

    #[DataProvider('decoratedNames')]
    public function test_a_published_name_cannot_be_decorated_into_a_mirrorable_one(string $requested): void
    {
        $this->mirroring();
        $this->fakeUpstream();

        // Published elsewhere, and its vendor deliberately *not* reserved: the
        // reservation limb would refuse all of these on its own, and what is
        // under test is the other limb — that a name this installation
        // publishes cannot be dressed up into one it does not.
        //
        // A route parameter arrives rawurldecoded and `{package}` is `[^/]+`,
        // so every one of these reaches the mirror as a distinct string. Each
        // then has to fail the name grammar, because each *would* miss the
        // published `acme/private` in the comparison that follows it.
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $this->makeLocalPackage('acme/private', $internal);

        $this->getJson("/p2/{$requested}.json")->assertNotFound();
        $this->get("/dist/{$requested}/".self::REFERENCE.'.zip')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_an_archive_reference_cannot_carry_a_trailing_newline(): void
    {
        Storage::fake(config('filesystems.dists'));

        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
            'cdn.upstream.test/*' => Http::response(self::ZIP),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertOk();

        // The reference becomes a path on the dist disk and a segment of an
        // outbound URL, and one pattern is where that is decided — the lookup
        // that follows compares it to what the upstream published and would
        // refuse this too, but only by accident of the newline being part of
        // the string it compares.
        $this->get('/dist/symfony/console/'.self::REFERENCE.'%0A.zip')->assertNotFound();

        $this->assertDatabaseCount('mirrored_archives', 0);
    }

    public function test_a_reserved_vendor_is_never_served_from_an_upstream(): void
    {
        $repository = $this->mirroring();
        $repository->reservedVendors()->create(['vendor' => 'acme']);

        // The reservation says this installation owns the whole prefix, which
        // has to mean an upstream's acme/anything is refused — that package is
        // exactly the one the reservation exists to keep out.
        $this->getJson('/p2/acme/not-published-yet.json')->assertNotFound();
    }

    public function test_a_private_package_stays_installable_while_the_upstream_is_down(): void
    {
        $this->mirroring();
        $this->makeLocalPackage('acme/widgets');

        Http::fake(fn () => throw new ConnectionException('Upstream is unreachable.'));

        $this->getJson('/packages.json')->assertOk();
        $versions = $this->versionsOf($this->getJson('/p2/acme/widgets.json')->assertOk(), 'acme/widgets');

        $this->assertSame('v1.0.0', $versions[0]['version']);

        // The local path never consults an upstream, so the outage is not
        // merely survived — it is never met.
        Http::assertNothingSent();
    }

    public function test_a_stale_cached_document_is_served_when_the_upstream_is_unreachable(): void
    {
        $repository = $this->mirroring();

        $payload = json_encode($this->upstreamDocument(), JSON_THROW_ON_ERROR);

        MirroredPackage::factory()->stale()->create([
            'upstream_id' => $repository->upstreams->first()?->getKey(),
            'name' => 'symfony/console',
            'is_dev' => false,
            'payload' => $payload,
            'digest' => hash('xxh128', $payload),
        ]);

        Http::fake(fn () => throw new ConnectionException('Upstream is unreachable.'));

        // Past its TTL, so this request tried to revalidate and could not. An
        // hour-old copy of symfony/console resolves a build; an error does not.
        $versions = $this->versionsOf($this->getJson('/p2/symfony/console.json')->assertOk());

        $this->assertSame('v6.0.0', $versions[0]['version']);
    }

    public function test_a_missing_package_is_remembered_rather_than_asked_about_twice(): void
    {
        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/nope/nothing.json' => Http::response('', 404),
        ]);

        $this->getJson('/p2/nope/nothing.json')->assertNotFound();
        $this->getJson('/p2/nope/nothing.json')->assertNotFound();

        $this->assertDatabaseHas('mirrored_packages', ['name' => 'nope/nothing', 'payload' => null]);

        Http::assertSentCount(2);
    }

    public function test_an_upstream_failure_is_never_recorded_as_a_missing_package(): void
    {
        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response('', 503),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertNotFound();

        // A negative entry here would hide the package for the whole missing
        // TTL, and hide the outage entirely.
        $this->assertDatabaseCount('mirrored_packages', 0);
    }

    public function test_revalidation_replays_the_upstreams_validators_and_a_304_leaves_the_etag_alone(): void
    {
        $repository = $this->mirroring();

        $payload = json_encode($this->upstreamDocument(), JSON_THROW_ON_ERROR);

        $mirrored = MirroredPackage::factory()->stale()->create([
            'upstream_id' => $repository->upstreams->first()?->getKey(),
            'name' => 'symfony/console',
            'is_dev' => false,
            'payload' => $payload,
            'digest' => hash('xxh128', $payload),
            'upstream_etag' => '"upstream-tag"',
        ]);

        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response('', 304),
        ]);

        $etag = $this->getJson('/p2/symfony/console.json')->assertOk()->headers->get('ETag');

        Http::assertSent(fn ($request): bool => $request->hasHeader('If-None-Match', '"upstream-tag"'));

        // The freshness clock moved; the bytes did not, so neither did the
        // validator every client is holding.
        $this->assertTrue($mirrored->refresh()->isFresh());

        $this->assertSame($etag, $this->getJson('/p2/symfony/console.json')->assertOk()->headers->get('ETag'));

        $this->getJson('/p2/symfony/console.json', ['If-None-Match' => (string) $etag])
            ->assertStatus(304);
    }

    public function test_an_upstream_cannot_smuggle_another_package_into_a_document(): void
    {
        $this->mirroring();

        $document = $this->upstreamDocument();
        // An upstream answering the question it was asked, plus one it was
        // not — and a version claiming a different name inside the entry it
        // was asked for.
        $document['packages']['acme/internal'] = [['name' => 'acme/internal', 'version' => 'v9.9.9']];
        $document['packages']['symfony/console'][0]['name'] = 'acme/internal';

        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($document),
        ]);

        $served = $this->getJson('/p2/symfony/console.json')->assertOk()->json('packages');

        $this->assertSame(['symfony/console'], array_keys((array) $served));
        $this->assertSame('symfony/console', $this->versionsOf($this->getJson('/p2/symfony/console.json'))[0]['name']);
    }

    public function test_mirrored_content_is_refused_to_a_token_that_cannot_see_the_repository(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        Upstream::factory()->create(['repository_id' => $internal->getKey(), 'url' => self::UPSTREAM]);

        $granted = Repository::factory()->create(['path' => 'granted', 'public' => false]);

        $deployToken = DeployToken::factory()->create();
        $deployToken->repositories()->attach($granted);

        $new = Token::issue($deployToken, $deployToken->name, [TokenAbility::RepositoryRead]);

        // The credential is live and the middleware lets it through, but it
        // was never granted this mount. Mirrored packages have no rows of
        // their own for per-package scoping to narrow, so without the
        // repository-level check this would serve a private upstream's
        // packages to a token that was granted somewhere else entirely.
        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/symfony/console.json')
            ->assertNotFound();
    }

    public function test_a_mirrored_archive_is_fetched_verified_stored_and_then_served_from_disk(): void
    {
        Storage::fake(config('filesystems.dists'));

        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
            'cdn.upstream.test/*' => Http::response(self::ZIP),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertOk();

        $download = $this->get('/dist/symfony/console/'.self::REFERENCE.'.zip')->assertOk();

        $this->assertSame(self::ZIP, $download->streamedContent());

        $archive = MirroredArchive::query()->sole();

        $this->assertSame(sha1(self::ZIP), $archive->shasum);
        $this->assertStringStartsWith('mirror/', (string) $archive->path);
        Storage::disk(config('filesystems.dists'))->assertExists((string) $archive->path);

        $sent = count(Http::recorded());

        // The whole point: the second install touches nothing outside this
        // installation.
        $this->get('/dist/symfony/console/'.self::REFERENCE.'.zip')->assertOk();

        Http::assertSentCount($sent);
    }

    public function test_an_upstreams_credential_is_never_sent_to_the_host_it_names(): void
    {
        Storage::fake(config('filesystems.dists'));

        $repository = Repository::default();
        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'url' => self::UPSTREAM,
            'token' => 'upstream-secret',
        ]);

        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
            'cdn.upstream.test/*' => Http::response(self::ZIP),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertOk();
        $this->get('/dist/symfony/console/'.self::REFERENCE.'.zip')->assertOk();

        // The upstream chooses the host its archives live on. Spending the
        // operator's credential against whatever third party it names would
        // hand a private registry's token to a CDN — and an object store
        // refuses a request carrying both a signature and an Authorization
        // header, so it would break signed dist URLs besides.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'upstream.test/p2/')
            && $request->hasHeader('Authorization'));

        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'cdn.upstream.test')
            || ! $request->hasHeader('Authorization'));
    }

    public function test_an_archive_that_does_not_match_the_published_shasum_is_refused(): void
    {
        Storage::fake(config('filesystems.dists'));

        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
            'cdn.upstream.test/*' => Http::response('not the bytes that were promised'),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertOk();

        // Composer would have caught this itself — it checks the same sha1 —
        // but only after this registry had already stored the bytes and served
        // them under a hash it was vouching for.
        $this->get('/dist/symfony/console/'.self::REFERENCE.'.zip')->assertNotFound();

        $this->assertDatabaseCount('mirrored_archives', 0);
        $this->assertSame([], Storage::disk(config('filesystems.dists'))->allFiles('mirror'));
    }

    public function test_a_failed_archive_download_does_not_take_the_upstream_offline(): void
    {
        Storage::fake(config('filesystems.dists'));

        $this->mirroring();
        $this->fakeUpstream([
            'upstream.test/p2/symfony/console.json' => Http::response($this->upstreamDocument()),
            'upstream.test/p2/symfony/finder.json' => Http::response($this->upstreamDocument('symfony/finder')),
            // A zipball whose GitHub repository was deleted or renamed. The
            // archive host is not the upstream's API, and a 404 from it says
            // nothing about whether the upstream is answering.
            'cdn.upstream.test/*' => Http::response('', 404),
        ]);

        $this->getJson('/p2/symfony/console.json')->assertOk();
        $this->get('/dist/symfony/console/'.self::REFERENCE.'.zip')->assertNotFound();

        // Otherwise one anonymous request for a broken reference switches
        // mirroring off for everyone for the length of the backoff.
        $this->getJson('/p2/symfony/finder.json')->assertOk();
    }

    public function test_advisories_are_passed_through_for_mirrored_packages(): void
    {
        $this->mirroring();
        $this->makeLocalPackage('acme/widgets');

        $this->fakeUpstream([
            'upstream.test/security-advisories' => Http::response([
                'advisories' => [
                    'symfony/console' => [[
                        'advisoryId' => 'PKSA-upstream-1',
                        'packageName' => 'symfony/console',
                        'affectedVersions' => '<6.0.1',
                        'title' => 'Something upstream knows about',
                        'sources' => [],
                        'reportedAt' => '2026-01-01 00:00:00',
                    ]],
                ],
            ]),
        ]);

        $response = $this->postJson('/security-advisories', [
            'packages' => ['symfony/console', 'acme/widgets'],
        ])->assertOk();

        $advisories = (array) $response->json('advisories');

        // Without the passthrough, `composer audit` — which now runs inside
        // every `composer update` — would report nothing for the mirrored
        // majority of a project's graph, indistinguishably from nobody having
        // checked.
        $this->assertSame('PKSA-upstream-1', $advisories['symfony/console'][0]['advisoryId']);

        // The local package is still answered locally, and answered even
        // though it is clean: an empty list is how Composer is told this
        // repository covers the name.
        $this->assertSame([], $advisories['acme/widgets']);
    }

    public function test_a_local_packages_advisories_are_never_asked_of_an_upstream(): void
    {
        $this->mirroring();
        $this->makeLocalPackage('acme/widgets');

        // Nothing stubbed at all: asking the upstream about a name this
        // registry publishes would be a stray request, and would also invite
        // an upstream to make claims about our own packages.
        $this->postJson('/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertJsonPath('advisories.acme/widgets', []);
    }
}
