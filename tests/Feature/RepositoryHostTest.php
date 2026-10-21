<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\BillingCustomer;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A repository with a host of its own answers at that host's root, builds
 * every URL on it, and keeps the rest of the app off it — while its /r/{path}
 * mount on the registry's address goes on behaving exactly as before.
 */
class RepositoryHostTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'composer.example.test';

    private Repository $branded;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branded = Repository::factory()->public()->create(['path' => 'branded', 'host' => self::HOST]);

        Package::factory()
            ->create(['name' => 'acme/widgets', 'repository_id' => $this->branded->id])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('a', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
            ]);
    }

    private function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Absolute, because the test client resolves a relative path against the
     * host of whichever request it made last.
     */
    private function hostUrl(string $path = ''): string
    {
        return parse_url($this->appUrl(), PHP_URL_SCHEME).'://'.self::HOST.$path;
    }

    public function test_the_host_is_stored_lowercase_and_bare(): void
    {
        $repository = Repository::factory()->create(['host' => ' Composer.Other.Test. ']);

        $this->assertSame('composer.other.test', $repository->host);
        $this->assertNull(Repository::factory()->create(['host' => ''])->host);
    }

    public function test_the_root_document_at_the_host_points_at_the_host(): void
    {
        $this->getJson($this->hostUrl('/packages.json'))
            ->assertOk()
            ->assertJsonPath('metadata-url', '/p2/%package%.json')
            ->assertJsonPath('list', $this->hostUrl('/list.json'));

        $this->getJson($this->hostUrl('/list.json'))
            ->assertOk()
            ->assertJsonPath('packageNames', ['acme/widgets']);
    }

    public function test_metadata_at_the_host_hands_out_dist_urls_on_the_host(): void
    {
        $response = $this->getJson($this->hostUrl('/p2/acme/widgets.json'))->assertOk();

        $this->assertSame(
            $this->hostUrl('/dist/acme/widgets/'.str_repeat('a', 40).'.zip'),
            $response->json('packages')['acme/widgets'][0]['dist']['url'],
        );
    }

    public function test_the_path_mount_keeps_every_url_on_the_registry_address(): void
    {
        $this->getJson($this->appUrl().'/r/branded/packages.json')
            ->assertOk()
            ->assertJsonPath('metadata-url', '/r/branded/p2/%package%.json')
            ->assertJsonPath('list', $this->appUrl().'/r/branded/list.json');

        $response = $this->getJson($this->appUrl().'/r/branded/p2/acme/widgets.json')->assertOk();

        $this->assertSame(
            $this->appUrl().'/r/branded/dist/acme/widgets/'.str_repeat('a', 40).'.zip',
            $response->json('packages')['acme/widgets'][0]['dist']['url'],
        );
    }

    public function test_the_two_addresses_are_cached_as_different_documents(): void
    {
        $atHost = $this->getJson($this->hostUrl('/p2/acme/widgets.json'))->assertOk()->headers->get('ETag');
        $atPath = $this->getJson($this->appUrl().'/r/branded/p2/acme/widgets.json')->assertOk()->headers->get('ETag');

        $this->assertNotNull($atHost);
        $this->assertNotSame($atHost, $atPath);
    }

    public function test_an_unknown_host_is_answered_as_the_registry_itself(): void
    {
        $this->getJson('http://forged.example.test/packages.json')
            ->assertOk()
            ->assertJsonPath('metadata-url', '/p2/%package%.json')
            ->assertJsonPath('list', $this->appUrl().'/list.json');

        // The default repository's packages, not the branded one's.
        $this->getJson('http://forged.example.test/list.json')
            ->assertOk()
            ->assertJsonPath('packageNames', []);
    }

    public function test_the_host_serves_no_other_repository(): void
    {
        Repository::factory()->public()->create(['path' => 'other']);

        $this->getJson($this->hostUrl('/r/other/packages.json'))->assertNotFound();
        $this->getJson($this->hostUrl('/r/branded/packages.json'))->assertNotFound();
    }

    public function test_the_rest_of_the_app_is_sent_back_to_the_registry_address(): void
    {
        $this->get($this->hostUrl('/admin/login'))->assertRedirect($this->appUrl().'/admin/login');
        $this->get($this->hostUrl('/admin'))->assertRedirect($this->appUrl().'/admin');
        $this->get($this->hostUrl('/billing?x=1'))->assertRedirect($this->appUrl().'/billing?x=1');
        $this->post($this->hostUrl('/register'))->assertNotFound();
    }

    public function test_the_hosts_own_pages_are_served_there(): void
    {
        $this->branded->update(['page_enabled' => true]);

        $this->get($this->hostUrl('/'))->assertOk()->assertSee($this->branded->name);
    }

    public function test_urls_built_outside_a_request_use_the_host(): void
    {
        $this->assertSame(
            'composer config repositories.'.$this->branded->composerKey().' composer '.$this->hostUrl(),
            $this->branded->fresh()->configureCommand(),
        );
    }

    public function test_npm_tarballs_follow_the_address_the_packument_was_asked_at(): void
    {
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        $token = Token::issue(
            User::factory()->superAdmin()->create(),
            'ci',
            [TokenAbility::RepositoryRead, TokenAbility::RepositoryWrite],
        )->plainText;

        $this->withToken($token)->putJson($this->hostUrl('/npm/gadgets'), [
            '_id' => 'gadgets',
            'name' => 'gadgets',
            'dist-tags' => ['latest' => '1.0.0'],
            'versions' => ['1.0.0' => ['name' => 'gadgets', 'version' => '1.0.0']],
            '_attachments' => ['gadgets-1.0.0.tgz' => [
                'content_type' => 'application/octet-stream',
                'data' => base64_encode('bytes'),
                'length' => 5,
            ]],
        ])->assertCreated();

        $atHost = $this->getJson($this->hostUrl('/npm/gadgets'))->assertOk()->json('versions')['1.0.0'];
        $atPath = $this->getJson($this->appUrl().'/r/branded/npm/gadgets')->assertOk()->json('versions')['1.0.0'];

        $this->assertSame($this->hostUrl('/npm/gadgets/-/gadgets-1.0.0.tgz'), $atHost['dist']['tarball']);
        $this->assertSame($this->appUrl().'/r/branded/npm/gadgets/-/gadgets-1.0.0.tgz', $atPath['dist']['tarball']);
    }

    public function test_each_host_has_its_own_sitemap(): void
    {
        config(['registry.pages.sitemap' => true]);

        $this->branded->update(['page_enabled' => true]);
        $unbranded = Repository::factory()->public()->create(['path' => 'plain', 'page_enabled' => true]);

        $this->get($this->hostUrl('/sitemap.xml'))
            ->assertOk()
            ->assertSee('<loc>'.$this->hostUrl().'</loc>', false)
            ->assertDontSee('/r/plain', false);

        $this->get($this->appUrl().'/sitemap.xml')
            ->assertOk()
            ->assertSee($unbranded->pageUrl(), false)
            ->assertDontSee(self::HOST, false);

        $this->get($this->hostUrl('/robots.txt'))->assertSee('Sitemap: '.$this->hostUrl('/sitemap.xml'));
    }

    public function test_the_registrys_own_host_is_never_resolved_to_a_repository(): void
    {
        // However such a row came to exist — an APP_URL typed in upper case
        // would once have slipped past the form's refusal — the registry's
        // own host keeps answering as the registry, and nothing on it is
        // sent redirecting to itself.
        config(['app.url' => 'https://Packages.Example.Test']);

        $claim = Repository::factory()->public()->create(['path' => 'claim', 'host' => 'packages.example.test']);
        Package::factory()->create(['name' => 'acme/claim', 'repository_id' => $claim->id]);

        $this->assertSame('packages.example.test', Repository::registryHost());
        $this->assertNull(Repository::forHost('packages.example.test'));

        $this->getJson('https://packages.example.test/list.json')
            ->assertOk()
            ->assertJsonPath('packageNames', []);
    }

    public function test_the_root_document_stays_conditional_when_both_addresses_are_used(): void
    {
        $modified = $this->getJson($this->hostUrl('/packages.json'))->assertOk()->headers->get('Last-Modified');
        $this->assertNotNull($modified);

        // Traffic on the path mount in between used to overwrite the host's
        // first-seen date, costing every client on the host its 304.
        $this->getJson($this->appUrl().'/r/branded/packages.json')->assertOk();

        $this->travel(1)->seconds();

        $this->getJson($this->hostUrl('/packages.json'), ['If-Modified-Since' => $modified])
            ->assertStatus(304);
    }

    public function test_the_tls_check_knows_only_the_registrys_hosts(): void
    {
        $this->get($this->appUrl().'/tls/ask?domain='.self::HOST)->assertNotFound();

        config(['registry.tls_ask' => true]);

        $this->get($this->appUrl().'/tls/ask?domain='.self::HOST)->assertOk();
        $this->get($this->appUrl().'/tls/ask?domain='.parse_url($this->appUrl(), PHP_URL_HOST))->assertOk();
        $this->get($this->appUrl().'/tls/ask?domain=elsewhere.test')->assertNotFound();
    }

    public function test_billing_sets_a_customer_up_at_the_host_their_plan_is_served_from(): void
    {
        config(['registry.billing.enabled' => true]);

        $plan = Plan::factory()->create();
        $plan->packages()->attach(Package::query()->where('name', 'acme/widgets')->sole());

        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer = BillingCustomer::factory()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);
        Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $plan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $plan->getKey()])->getKey(),
        ]);

        $this->assertSame([$this->branded->id], $plan->setupRepositories()->modelKeys());

        $this->actingAs($user)->get($this->appUrl().'/billing')
            ->assertOk()
            ->assertSee('http-basic.'.self::HOST, false)
            ->assertSee($this->branded->fresh()->configureCommand(), false);
    }
}
