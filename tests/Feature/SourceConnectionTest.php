<?php

namespace Tests\Feature;

use App\Enums\SourceProvider;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => $pem,
            'api_url' => 'https://api.github.com',
        ]);

        // A request this suite forgot to fake must fail, not reach GitHub.
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create());
    }

    private function fakeInstallation(string $account = 'acme'): void
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'api.github.com/app/installations/42' => Http::response([
                'account' => ['login' => $account, 'type' => 'Organization'],
                'app_slug' => 'acme-pipeline',
                'repository_selection' => 'selected',
            ]),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 4]),
        ]);
    }

    public function test_connect_redirects_to_the_github_install_screen(): void
    {
        $source = Source::factory()->disconnected()->create();

        $response = $this->get(route('sources.connect', $source));

        $response->assertRedirectContains('https://github.com/apps/acme-pipeline/installations/new');
        $response->assertSessionHas('sources.github.pending');
    }

    public function test_the_app_slug_is_read_from_github_when_it_is_not_configured(): void
    {
        // Only the id and key are set; the slug is what builds the install URL.
        config()->set('services.github.app.slug', null);

        Http::fake(['api.github.com/app' => Http::response(['id' => 123456, 'slug' => 'derived-slug'])]);

        $source = Source::factory()->disconnected()->create();

        $this->get(route('sources.connect', $source))
            ->assertRedirectContains('https://github.com/apps/derived-slug/installations/new');
    }

    public function test_the_derived_slug_is_only_looked_up_once(): void
    {
        config()->set('services.github.app.slug', null);

        Http::fake(['api.github.com/app' => Http::response(['slug' => 'derived-slug'])]);

        $source = Source::factory()->disconnected()->create();

        $this->get(route('sources.connect', $source));
        $this->get(route('sources.connect', $source));

        Http::assertSentCount(1);
    }

    public function test_connecting_with_no_source_creates_one_from_the_installation(): void
    {
        $this->fakeInstallation('acme');

        // Nothing exists and nothing was typed — just the button.
        $this->assertDatabaseCount('sources', 0);

        $this->get(route('sources.connect.new'))
            ->assertRedirectContains('https://github.com/apps/acme-pipeline/installations/new');

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'setup_action' => 'install',
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $source = Source::sole();

        $this->assertSame('acme', $source->name);
        $this->assertSame('acme', $source->account);
        $this->assertSame('Organization', $source->account_type);
        $this->assertSame(42, $source->installation_id);
        $this->assertSame(SourceProvider::Github, $source->provider);
        $this->assertTrue($source->isConnected());
    }

    public function test_connecting_an_account_that_already_has_a_source_reuses_it(): void
    {
        $this->fakeInstallation('acme');

        // Added by hand before the app existed.
        $existing = Source::factory()->disconnected()->create(['name' => 'Acme', 'account' => 'ACME']);

        $this->get(route('sources.connect.new'));

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $this->assertDatabaseCount('sources', 1);
        $this->assertSame($existing->id, Source::sole()->id);
        $this->assertSame(42, $existing->fresh()->installation_id);
        // The name the admin chose is kept.
        $this->assertSame('Acme', $existing->fresh()->name);
    }

    public function test_a_created_source_name_does_not_collide_with_an_existing_one(): void
    {
        $this->fakeInstallation('acme');

        // Same name, different account — so it is not the same source.
        Source::factory()->create(['name' => 'acme', 'account' => 'someone-else']);

        $this->get(route('sources.connect.new'));

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $this->assertSame('acme (2)', Source::query()->where('account', 'acme')->sole()->name);
    }

    public function test_reconnecting_onto_an_account_another_source_holds_is_refused(): void
    {
        $this->fakeInstallation('acme');

        $held = Source::factory()->create(['name' => 'Acme', 'account' => 'acme', 'installation_id' => 99]);
        $other = Source::factory()->disconnected()->create(['name' => 'Spare', 'account' => null]);

        $this->get(route('sources.connect', $other));

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $this->assertNull($other->fresh()->installation_id);
        $this->assertSame(99, $held->fresh()->installation_id);
    }

    public function test_both_legs_of_the_handshake_require_authentication(): void
    {
        $this->fakeInstallation();

        $source = Source::factory()->disconnected()->create();

        Auth::logout();
        $this->flushSession();

        $this->assertGuest();

        $this->get(route('sources.connect', $source))->assertRedirect('/admin/login');
        $this->get(route('sources.github.callback', ['installation_id' => 42]))->assertRedirect('/admin/login');

        // An anonymous callback must never be able to attach an installation.
        $this->assertNull($source->fresh()->installation_id);
        Http::assertNothingSent();
    }

    public function test_the_callback_records_the_installation_and_verifies_it(): void
    {
        $this->fakeInstallation();

        $source = Source::factory()->disconnected()->create(['account' => null]);

        $this->get(route('sources.connect', $source));

        $state = session('sources.github.pending')['state'];

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'setup_action' => 'install',
            'state' => $state,
        ]))->assertRedirect();

        $source->refresh();

        $this->assertSame(42, $source->installation_id);
        $this->assertSame('acme', $source->account);
        $this->assertSame('Organization', $source->account_type);
        $this->assertSame('selected', $source->metadata['repository_selection']);
        $this->assertSame(4, $source->metadata['repository_count']);
        $this->assertTrue($source->isConnected());
    }

    public function test_connecting_claims_packages_already_pointing_at_the_account(): void
    {
        $this->fakeInstallation();

        // Added before the source existed, so auto-linking on save found
        // nothing to attach.
        $mine = Package::factory()->create(['repository' => 'https://github.com/ACME/widgets']);
        $theirs = Package::factory()->create(['repository' => 'https://github.com/other/widgets']);
        $broken = Package::factory()->create(['repository' => 'not-a-repository-url']);

        $source = Source::factory()->disconnected()->create(['account' => null]);

        $this->get(route('sources.connect', $source));

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $this->assertSame($source->id, $mine->fresh()->source_id);
        $this->assertNull($theirs->fresh()->source_id);
        $this->assertNull($broken->fresh()->source_id);
    }

    public function test_a_callback_with_a_mismatched_state_is_rejected(): void
    {
        $this->fakeInstallation();

        $source = Source::factory()->disconnected()->create();

        $this->get(route('sources.connect', $source));

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => 'forged-state',
        ]))->assertRedirect();

        $this->assertNull($source->fresh()->installation_id);
        Http::assertNothingSent();
    }

    public function test_a_callback_with_no_pending_connection_is_rejected(): void
    {
        $this->fakeInstallation();

        $this->get(route('sources.github.callback', [
            'installation_id' => 42,
            'state' => 'anything',
        ]))->assertRedirect();

        Http::assertNothingSent();
        $this->assertDatabaseCount('sources', 0);
    }

    public function test_the_state_cannot_be_replayed(): void
    {
        $this->fakeInstallation();

        $source = Source::factory()->disconnected()->create(['account' => null]);

        $this->get(route('sources.connect', $source));
        $state = session('sources.github.pending')['state'];

        $this->get(route('sources.github.callback', ['installation_id' => 42, 'state' => $state]));

        $other = Source::factory()->disconnected()->create();

        // A second callback carrying the same state finds nothing pending, so
        // it cannot attach the installation anywhere.
        $this->get(route('sources.github.callback', ['installation_id' => 99, 'state' => $state]));

        $this->assertNull($other->fresh()->installation_id);
    }

    public function test_a_cancelled_install_leaves_the_source_untouched(): void
    {
        $source = Source::factory()->disconnected()->create();

        $this->get(route('sources.connect', $source));

        $this->get(route('sources.github.callback', [
            'state' => session('sources.github.pending')['state'],
        ]))->assertRedirect();

        $this->assertNull($source->fresh()->installation_id);
        $this->assertFalse($source->fresh()->isConnected());
    }

    public function test_connect_reports_a_missing_app_configuration(): void
    {
        // The key is what makes an app usable; the slug is derived from it.
        config()->set('services.github.app.private_key', null);

        $source = Source::factory()->disconnected()->create();

        $this->get(route('sources.connect', $source))
            ->assertRedirect()
            ->assertSessionMissing('sources.github.pending');
    }
}
