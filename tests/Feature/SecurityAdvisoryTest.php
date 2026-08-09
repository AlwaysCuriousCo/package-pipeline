<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\RelationManagers\AdvisoriesRelationManager;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\PackageAdvisory;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The security-advisories endpoint, which is the whole of what `composer
 * audit` can learn about a package served from this registry.
 *
 * The request shape asserted here is Composer's own: a form-encoded POST of
 * `http_build_query(['packages' => [...]])` to the `api-url` the root
 * advertises, answered with `{"advisories": {"vendor/name": [...]}}` — all of
 * it read from Composer's own
 * Repository\ComposerRepository::getSecurityAdvisories().
 */
class SecurityAdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeServedPackage(string $name = 'acme/widgets', ?Repository $repository = null): Package
    {
        $package = Package::factory()->create([
            'name' => $name,
            'repository_id' => $repository?->id,
        ]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    public function test_the_root_advertises_the_advisory_endpoint(): void
    {
        $this->makeServedPackage();

        $this->get('/packages.json')
            ->assertOk()
            // Composer only asks at all when api-url is set; metadata:false
            // says the per-package /p2 documents carry no advisories.
            ->assertJsonPath('security-advisories.api-url', url('/security-advisories'))
            ->assertJsonPath('security-advisories.metadata', false);
    }

    public function test_a_named_repository_advertises_its_own_advisory_endpoint(): void
    {
        $internal = Repository::factory()->public()->create(['path' => 'internal']);

        $this->makeServedPackage('acme/gadgets', $internal);

        $this->get('/r/internal/packages.json')
            ->assertOk()
            ->assertJsonPath('security-advisories.api-url', url('/r/internal/security-advisories'));
    }

    public function test_it_answers_the_request_composer_audit_sends(): void
    {
        $package = $this->makeServedPackage();

        $advisory = PackageAdvisory::factory()->create([
            'package_id' => $package->id,
            'advisory_id' => 'PPSA-1111-2222-3333',
            'title' => 'Authentication bypass in the webhook verifier',
            'affected_versions' => '>=1.0,<1.4.2',
            'severity' => 'critical',
            'cve' => 'CVE-2026-1234',
            'link' => 'https://example.test/advisories/1',
        ]);

        // The body Composer builds, run through the parser PHP applies to a
        // form-encoded POST — so the `packages[0]=…` bracket encoding it
        // actually sends is what this asserts the endpoint reads. No Accept
        // header either: Composer does not ask for JSON.
        parse_str(http_build_query(['packages' => ['acme/widgets']]), $body);

        $response = $this->call(
            'POST',
            '/security-advisories',
            $body,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );

        $response->assertOk()
            ->assertJsonPath('advisories.acme/widgets.0.advisoryId', 'PPSA-1111-2222-3333')
            ->assertJsonPath('advisories.acme/widgets.0.packageName', 'acme/widgets')
            ->assertJsonPath('advisories.acme/widgets.0.affectedVersions', '>=1.0,<1.4.2')
            ->assertJsonPath('advisories.acme/widgets.0.title', 'Authentication bypass in the webhook verifier')
            ->assertJsonPath('advisories.acme/widgets.0.cve', 'CVE-2026-1234')
            ->assertJsonPath('advisories.acme/widgets.0.link', 'https://example.test/advisories/1')
            ->assertJsonPath('advisories.acme/widgets.0.severity', 'critical')
            // Composer reads an advisory as *full* only when title, sources
            // and reportedAt are all present, and `composer audit` throws on a
            // partial one — so these two are as load-bearing as the title.
            ->assertJsonPath('advisories.acme/widgets.0.sources.0.remoteId', 'PPSA-1111-2222-3333')
            ->assertJsonPath(
                'advisories.acme/widgets.0.reportedAt',
                $advisory->reported_at->toIso8601String(),
            );
    }

    public function test_it_answers_a_get_with_the_same_shape(): void
    {
        $package = $this->makeServedPackage();

        PackageAdvisory::factory()->create(['package_id' => $package->id]);

        $this->get('/security-advisories?'.http_build_query(['packages' => ['acme/widgets']]))
            ->assertOk()
            ->assertJsonCount(1, 'advisories.acme/widgets');
    }

    public function test_a_package_with_no_advisories_is_reported_as_known_and_clean(): void
    {
        $this->makeServedPackage();

        // An empty list, not an absent key: presence is how Composer learns
        // this repository covers the name and had nothing to say about it.
        $this->postJson('/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertExactJson(['advisories' => ['acme/widgets' => []]]);
    }

    public function test_a_package_this_repository_does_not_serve_is_absent(): void
    {
        $this->makeServedPackage();

        $this->postJson('/security-advisories', ['packages' => ['other/thing']])
            ->assertOk()
            // `{}` rather than `[]`: Composer iterates this as a map.
            ->assertExactJson(['advisories' => []]);
    }

    public function test_no_packages_is_an_empty_answer_rather_than_an_error(): void
    {
        $this->makeServedPackage();

        $this->postJson('/security-advisories')
            ->assertOk()
            ->assertExactJson(['advisories' => []]);
    }

    public function test_a_private_repository_refuses_an_unauthenticated_request(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $package = $this->makeServedPackage('acme/secret', $internal);

        PackageAdvisory::factory()->create(['package_id' => $package->id]);

        $this->postJson('/r/internal/security-advisories', ['packages' => ['acme/secret']])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Composer repository"');
    }

    public function test_a_token_hears_nothing_about_a_package_it_cannot_see(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $hidden = $this->makeServedPackage('acme/secret', $internal);
        $granted = $this->makeServedPackage('acme/widgets', $internal);

        PackageAdvisory::factory()->create(['package_id' => $hidden->id]);
        PackageAdvisory::factory()->create(['package_id' => $granted->id]);

        $deployToken = DeployToken::factory()->create();
        $deployToken->packages()->attach($granted);

        $plain = Token::issue($deployToken, 'ci', [TokenAbility::RepositoryRead])->plainText;

        $response = $this->withToken($plain)
            ->postJson('/r/internal/security-advisories', ['packages' => ['acme/secret', 'acme/widgets']]);

        $response->assertOk()
            ->assertJsonCount(1, 'advisories.acme/widgets')
            // Not an empty list either — an empty list would confirm the
            // package exists here, which is exactly what the grant withholds.
            ->assertJsonMissingPath('advisories.acme/secret');
    }

    public function test_advisories_are_scoped_to_the_repository_the_request_addressed(): void
    {
        $internal = Repository::factory()->public()->create(['path' => 'internal']);

        $this->makeServedPackage('acme/widgets');
        $twin = $this->makeServedPackage('acme/widgets', $internal);

        PackageAdvisory::factory()->create(['package_id' => $twin->id]);

        // The default repository's package of the same name is unaffected.
        $this->postJson('/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertExactJson(['advisories' => ['acme/widgets' => []]]);

        $this->postJson('/r/internal/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertJsonCount(1, 'advisories.acme/widgets');
    }

    public function test_the_response_is_keyed_by_the_spelling_that_was_asked_for(): void
    {
        $package = $this->makeServedPackage();

        PackageAdvisory::factory()->create(['package_id' => $package->id]);

        // Composer discards any name it did not itself request, so echoing
        // back the stored spelling would throw the advisory away.
        $this->postJson('/security-advisories', ['packages' => ['Acme/Widgets']])
            ->assertOk()
            ->assertJsonCount(1, 'advisories.Acme/Widgets');
    }

    public function test_an_admin_can_record_an_advisory_against_a_package(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $package = $this->makeServedPackage();

        Livewire::test(AdvisoriesRelationManager::class, [
            'ownerRecord' => $package,
            'pageClass' => ViewPackage::class,
        ])
            ->callAction(TestAction::make('create')->table(), data: [
                'title' => 'Command injection in the sync worker',
                'affected_versions' => '<2.0.1',
                'severity' => 'high',
                'reported_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $advisory = $package->advisories()->sole();

        $this->assertSame('Command injection in the sync worker', $advisory->title);
        // Left blank on the form, so the model issued one — an advisory with
        // no id is one no consumer could ever silence.
        $this->assertMatchesRegularExpression('/^PPSA-\d{4}-\d{4}-\d{4}$/', (string) $advisory->advisory_id);
        // Null source is the discriminator for "recorded here", as opposed to
        // imported from a feed.
        $this->assertNull($advisory->source);
    }

    public function test_recording_an_advisory_borrows_the_packages_update_permission(): void
    {
        // Advisories have no Shield permissions of their own, by design: the
        // right to say a package is vulnerable is the right to edit it. A role
        // that may look at packages but not change them must not be able to.
        $user = User::factory()->create();
        $user->assignRole(Role::create(['name' => 'observer', 'guard_name' => 'web']));
        $user->givePermissionTo('View:Package');

        $this->actingAs($user);

        Livewire::test(AdvisoriesRelationManager::class, [
            'ownerRecord' => $this->makeServedPackage(),
            'pageClass' => ViewPackage::class,
        ])->assertActionHidden(TestAction::make('create')->table());
    }

    public function test_an_imported_advisory_is_published_under_its_own_source_name(): void
    {
        $package = $this->makeServedPackage();

        PackageAdvisory::factory()
            ->fromSource('FriendsOfPHP/security-advisories')
            ->create(['package_id' => $package->id]);

        $this->postJson('/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertJsonPath('advisories.acme/widgets.0.sources.0.name', 'FriendsOfPHP/security-advisories');
    }
}
