<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CreatePackageWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * Fake the composer.json the wizard reads off the default branch.
     */
    private function fakeComposerJson(mixed $response = null): void
    {
        Http::fake([
            'api.github.com/repos/acme/Widgets/contents/composer.json*' => $response ?? Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets for Acme.',
                'type' => 'library',
            ]),
        ]);
    }

    public function test_the_second_step_opens_with_the_repository_composer_json(): void
    {
        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet([
                'name' => 'acme/widgets',
                'description' => 'Widgets for Acme.',
            ]);
    }

    public function test_the_deciphered_details_can_be_customised_before_creating(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->fillForm(['name' => 'acme/widgets-fork', 'description' => 'Our fork.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('packages', [
            'name' => 'acme/widgets-fork',
            'repository' => 'https://github.com/acme/Widgets',
            'description' => 'Our fork.',
            // The type and the latest version are the first sync's to fill in.
            'type' => null,
            'latest_version' => null,
        ]);
    }

    /**
     * A monorepo's manifest is not at the repository root, so the wizard's
     * read has to follow the subdirectory the first step names.
     */
    public function test_the_wizard_reads_the_manifest_from_the_subdirectory(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Http::fake([
            'api.github.com/repos/acme/Mono/contents/packages/widgets/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets, from the monorepo.',
            ]),
        ]);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'repository' => 'https://github.com/acme/Mono',
                'subdirectory' => '/packages/widgets/',
            ])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['name' => 'acme/widgets'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('packages', [
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/Mono',
            // Folded on the way in, whatever the field admitted.
            'subdirectory' => 'packages/widgets',
        ]);
    }

    public function test_the_wizard_refuses_a_subdirectory_that_climbs_out_of_the_repository(): void
    {
        Livewire::test(CreatePackage::class)
            ->fillForm([
                'repository' => 'https://github.com/acme/Mono',
                'subdirectory' => '../secrets',
            ])
            ->goToNextWizardStep()
            ->assertHasFormErrors(['subdirectory']);
    }

    public function test_a_repository_that_cannot_be_read_falls_back_to_the_url(): void
    {
        // GitHub answers 404 for a private repository as readily as for a
        // missing composer.json, so the name has to come from the URL.
        $this->fakeComposerJson(Http::response([], 404));

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets.git'])
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['name' => 'acme/widgets']);
    }

    public function test_returning_to_the_first_step_does_not_overwrite_the_name(): void
    {
        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->fillForm(['name' => 'acme/widgets-fork'])
            ->goToPreviousWizardStep()
            ->goToNextWizardStep()
            ->assertSchemaStateSet(['name' => 'acme/widgets-fork']);

        // The unchanged repository is read once, not once per visit.
        Http::assertSentCount(1);
    }

    public function test_changing_the_repository_deciphers_it_again(): void
    {
        Http::fake([
            'api.github.com/repos/acme/Widgets/contents/composer.json*' => Http::response(['name' => 'acme/widgets']),
            'api.github.com/repos/acme/gadgets/contents/composer.json*' => Http::response(['name' => 'acme/gadgets']),
        ]);

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->goToPreviousWizardStep()
            ->fillForm(['repository' => 'https://github.com/acme/gadgets'])
            ->goToNextWizardStep()
            ->assertSchemaStateSet(['name' => 'acme/gadgets']);
    }

    public function test_adding_a_token_after_a_failed_read_deciphers_the_repository_again(): void
    {
        // The first read has no credentials and 404s; the admin goes back,
        // enters a token, and the same URL must be read again — this time
        // successfully.
        Http::fake([
            'api.github.com/repos/acme/Widgets/contents/composer.json*' => Http::sequence()
                ->push([], 404)
                ->push(['name' => 'acme/widgets', 'description' => 'Widgets for Acme.']),
        ]);

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->goToPreviousWizardStep()
            ->fillForm(['token' => 'ghp_typed'])
            ->goToNextWizardStep()
            ->assertSchemaStateSet([
                'name' => 'acme/widgets',
                'description' => 'Widgets for Acme.',
            ]);

        Http::assertSentCount(2);
    }

    public function test_the_repository_is_read_through_the_source_that_owns_it(): void
    {
        Source::factory()->withToken('github_pat_acme')->create(['account' => 'Acme']);

        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/Widgets'])
            ->goToNextWizardStep()
            ->assertSchemaStateSet(['name' => 'acme/widgets']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer github_pat_acme'));
    }

    public function test_a_token_typed_into_the_wizard_is_used_to_read_the_repository(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'repository' => 'https://github.com/acme/Widgets',
                'token' => 'ghp_typed',
            ])
            ->goToNextWizardStep()
            ->call('create')
            ->assertHasNoFormErrors();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ghp_typed'));

        $this->assertSame('ghp_typed', Package::query()->sole()->token);
    }

    public function test_the_first_step_still_validates_the_repository(): void
    {
        $this->fakeComposerJson();

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'not-a-url'])
            ->goToNextWizardStep()
            ->assertHasFormErrors(['repository' => 'url']);

        Http::assertNothingSent();
    }
}
