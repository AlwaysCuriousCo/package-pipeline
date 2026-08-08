<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\Pages\CreateDeployToken;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Support\NewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deploy tokens are machine principals whose visibility is the union of
 * their granted repositories and packages — or everything when unscoped.
 */
class DeployTokenTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    private Repository $other;

    private Package $widgets;

    private Package $gadgets;

    private Package $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $this->other = Repository::factory()->create(['path' => 'other', 'public' => false]);

        $this->widgets = $this->makeServedPackage('acme/widgets', $this->internal);
        $this->gadgets = $this->makeServedPackage('acme/gadgets', $this->internal);
        $this->secret = $this->makeServedPackage('acme/secret', $this->other);
    }

    private function makeServedPackage(string $name, Repository $repository): Package
    {
        $package = Package::factory()->create(['name' => $name, 'repository_id' => $repository->id]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => sha1($name),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    private function issueFor(DeployToken $deployToken): NewToken
    {
        return Token::issue($deployToken, $deployToken->name, [TokenAbility::RepositoryRead]);
    }

    public function test_an_unscoped_deploy_token_sees_everything(): void
    {
        $new = $this->issueFor(DeployToken::factory()->create());

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/gadgets', 'acme/widgets']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/secret']]);
    }

    public function test_a_package_scoped_token_sees_only_its_grants(): void
    {
        $deployToken = DeployToken::factory()->create();
        $deployToken->packages()->attach($this->widgets);

        $new = $this->issueFor($deployToken);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/search.json?q=acme/')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/acme/gadgets.json')
            ->assertNotFound();

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/dist/acme/gadgets/'.sha1('acme/gadgets').'.zip')
            ->assertNotFound();

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => []]);
    }

    public function test_a_repository_scoped_token_sees_that_repositorys_packages(): void
    {
        $deployToken = DeployToken::factory()->create();
        $deployToken->repositories()->attach($this->internal);

        $new = $this->issueFor($deployToken);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/gadgets', 'acme/widgets']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/p2/acme/secret.json')
            ->assertNotFound();
    }

    public function test_a_scoped_token_still_reads_public_repositories(): void
    {
        $this->makeServedPackage('acme/open', Repository::default());

        $deployToken = DeployToken::factory()->create();
        $deployToken->packages()->attach($this->widgets);

        $new = $this->issueFor($deployToken);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/open']]);
    }

    public function test_deleting_the_deploy_token_revokes_its_access_token(): void
    {
        $deployToken = DeployToken::factory()->create();
        $new = $this->issueFor($deployToken);

        $this->withBasicAuth('token', $new->plainText)->getJson('/r/internal/list.json')->assertOk();

        $deployToken->delete();

        $this->withBasicAuth('token', $new->plainText)->getJson('/r/internal/list.json')->assertUnauthorized();
    }

    public function test_creating_in_the_panel_issues_a_read_token_and_stores_the_grants(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateDeployToken::class)
            ->fillForm([
                'name' => 'production-deploys',
                'repositories' => [$this->internal->id],
                'packages' => [$this->secret->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $deployToken = DeployToken::query()->where('name', 'production-deploys')->sole();

        $this->assertTrue($deployToken->repositories->contains($this->internal));
        $this->assertTrue($deployToken->packages->contains($this->secret));

        $token = $deployToken->token;

        $this->assertNotNull($token);
        $this->assertSame([TokenAbility::RepositoryRead->value], $token->abilities);
    }
}
