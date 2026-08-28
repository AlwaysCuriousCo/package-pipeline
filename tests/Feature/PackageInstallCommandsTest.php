<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PackageInstallCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_released_package_suggests_a_caret_constraint(): void
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'latest_version' => 'v1.1.0',
        ]);

        $commands = $package->installCommands();

        $this->assertSame('composer require acme/widgets:^1.1.0', $commands['require']);
        $this->assertSame(
            'composer config repositories.'.Str::slug(config('app.name')).' composer '.rtrim(url('/'), '/'),
            $commands['repository'],
        );
    }

    public function test_an_unreleased_package_prefers_its_default_dev_branch(): void
    {
        $package = Package::factory()->unreleased()->create(['name' => 'acme/widgets']);

        $package->versions()->create([
            'version' => '2.x-dev',
            'reference' => str_repeat('e', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => '2.x-dev'],
        ]);
        $package->versions()->create([
            'version' => 'dev-main',
            'reference' => str_repeat('d', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-main'],
        ]);

        $this->assertSame('composer require acme/widgets:dev-main', $package->installCommands()['require']);
    }

    public function test_an_unreleased_package_falls_back_to_any_dev_version(): void
    {
        $package = Package::factory()->unreleased()->create(['name' => 'acme/widgets']);

        $package->versions()->create([
            'version' => '2.x-dev',
            'reference' => str_repeat('e', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => '2.x-dev'],
        ]);

        $this->assertSame('composer require acme/widgets:2.x-dev', $package->installCommands()['require']);
    }

    public function test_a_package_with_no_versions_suggests_no_constraint(): void
    {
        $package = Package::factory()->unreleased()->create(['name' => 'acme/widgets']);

        $this->assertSame('composer require acme/widgets', $package->installCommands()['require']);
    }

    public function test_a_configured_key_is_used_verbatim(): void
    {
        config(['registry.composer_repository_key' => 'alwayscurious']);

        $repository = Repository::create(['name' => 'Composer', 'path' => 'composer']);

        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'latest_version' => 'v1.1.0',
            'repository_id' => $repository->id,
        ]);

        // No slugifying, and no "-composer" for the path: whoever set the key
        // decided what the entry in composer.json is called.
        $this->assertSame(
            'composer config repositories.alwayscurious composer '.url('/r/composer'),
            $package->installCommands()['repository'],
        );
    }

    public function test_the_details_page_shows_the_install_commands(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'latest_version' => 'v1.1.0',
        ]);

        $this->get("/admin/packages/{$package->getRouteKey()}")
            ->assertOk()
            ->assertSee('composer require acme/widgets:^1.1.0')
            ->assertSee('composer config repositories.'.Str::slug(config('app.name')).' composer');
    }

    public function test_the_panel_install_card_switches_the_register_command_between_repositories(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $internal = Repository::factory()->public()->create(['path' => 'internal']);
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->serveFrom([$internal]);

        Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
            ->assertSee($package->composerRepository->configureCommand())
            ->assertDontSee($internal->configureCommand())
            ->set('installRepository', $internal->id)
            ->assertSee($internal->configureCommand())
            ->assertDontSee($package->composerRepository->configureCommand());
    }

    public function test_the_panel_install_card_mints_a_read_token_for_a_private_repository(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        $private = Repository::factory()->create(['path' => 'private', 'public' => false]);
        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $private->id]);

        $component = Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
            ->assertSee('Generate a token above')
            ->assertSeeHtml('API token</a>')
            ->assertSeeHtml('deploy token</a>')
            ->callAction(TestAction::make('generateToken')->schemaComponent('install'), ['name' => 'laptop'])
            ->assertHasNoActionErrors()
            ->assertNotified('Token created');

        $plain = $component->get('plainTextToken');
        $component->assertSee('http-basic.'.request()->getHost()." token {$plain}");

        $token = Token::findByPlainText($plain);
        $this->assertSame('laptop', $token->name);
        $this->assertSame(['repository:read'], $token->abilities);
        $this->assertTrue($token->tokenable->is($user));
    }

    public function test_the_panel_install_card_offers_no_token_for_a_public_repository(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
            ->assertDontSee('Generate token')
            ->assertDontSee('Authenticate');
    }
}
