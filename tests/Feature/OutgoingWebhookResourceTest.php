<?php

namespace Tests\Feature;

use App\Enums\WebhookEvent;
use App\Filament\Resources\OutgoingWebhooks\OutgoingWebhookResource;
use App\Filament\Resources\OutgoingWebhooks\Pages\CreateOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\EditOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\ListOutgoingWebhooks;
use App\Jobs\DeliverWebhook;
use App\Models\OutgoingWebhook;
use App\Models\Repository;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OutgoingWebhookResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_index_lists_endpoints(): void
    {
        $webhooks = OutgoingWebhook::factory()->count(3)->create();

        Livewire::test(ListOutgoingWebhooks::class)
            ->assertCanSeeTableRecords($webhooks);
    }

    public function test_an_endpoint_is_created_with_its_events_and_secret(): void
    {
        Livewire::test(CreateOutgoingWebhook::class)
            ->fillForm([
                'name' => 'Deploy pipeline',
                'url' => 'https://ci.example.com/hooks/registry',
                'secret' => 'a-shared-secret',
                'events' => [WebhookEvent::VersionPublished->value],
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $webhook = OutgoingWebhook::query()->sole();

        $this->assertSame('a-shared-secret', $webhook->secret);
        $this->assertTrue($webhook->subscribesTo(WebhookEvent::VersionPublished));
        $this->assertFalse($webhook->subscribesTo(WebhookEvent::SyncFailed));
    }

    /**
     * The form leaves the scope empty by default, which is the registry-wide
     * meaning every endpoint had before the field existed — so creating one
     * without touching it must not start scoping it to something.
     */
    public function test_an_endpoint_is_registry_wide_unless_a_repository_is_chosen(): void
    {
        Livewire::test(CreateOutgoingWebhook::class)
            ->fillForm([
                'name' => 'Deploy pipeline',
                'url' => 'https://ci.example.com/hooks/registry',
                'events' => [WebhookEvent::VersionPublished->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(OutgoingWebhook::query()->sole()->repository_id);
    }

    public function test_an_endpoint_can_be_confined_to_one_repository(): void
    {
        $repository = Repository::factory()->create(['path' => 'internal']);

        Livewire::test(CreateOutgoingWebhook::class)
            ->fillForm([
                'name' => 'Internal pipeline',
                'repository_id' => $repository->getKey(),
                'url' => 'https://ci.example.com/hooks/registry',
                'events' => [WebhookEvent::VersionPublished->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(OutgoingWebhook::query()->sole()->repository->is($repository));
    }

    /**
     * An endpoint scoped to a repository that has gone is not an endpoint
     * scoped to the registry — widening it on a delete is exactly the
     * disclosure the column exists to prevent.
     */
    public function test_deleting_a_repository_takes_the_endpoints_scoped_to_it(): void
    {
        $repository = Repository::factory()->create(['path' => 'internal']);

        OutgoingWebhook::factory()->scopedTo($repository)->create();
        $registryWide = OutgoingWebhook::factory()->create();

        $repository->delete();

        $this->assertSame([$registryWide->getKey()], OutgoingWebhook::query()->pluck('id')->all());
    }

    /**
     * An endpoint subscribed to nothing looks configured and does nothing,
     * which is the failure this page exists to prevent.
     */
    public function test_an_endpoint_must_subscribe_to_something(): void
    {
        Livewire::test(CreateOutgoingWebhook::class)
            ->fillForm([
                'name' => 'Deploy pipeline',
                'url' => 'https://ci.example.com/hooks/registry',
                'events' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['events']);
    }

    /**
     * The secret is never rendered back, so an edit that does not retype it
     * must keep the stored one rather than blanking it.
     */
    public function test_editing_without_retyping_the_secret_keeps_it(): void
    {
        $webhook = OutgoingWebhook::factory()->create(['secret' => 'a-shared-secret']);

        Livewire::test(EditOutgoingWebhook::class, ['record' => $webhook->getKey()])
            ->fillForm(['name' => 'Renamed', 'secret' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $webhook->refresh();

        $this->assertSame('Renamed', $webhook->name);
        $this->assertSame('a-shared-secret', $webhook->secret);
    }

    public function test_a_test_delivery_can_be_sent_from_the_table(): void
    {
        Queue::fake();

        $webhook = OutgoingWebhook::factory()->create();

        Livewire::test(ListOutgoingWebhooks::class)
            ->callAction(TestAction::make('test')->table($webhook));

        Queue::assertPushed(
            DeliverWebhook::class,
            fn (DeliverWebhook $job): bool => $job->webhook->is($webhook)
                && $job->event === WebhookEvent::Ping,
        );
    }

    /**
     * A failing endpoint is invisible otherwise — its deliveries are queued,
     * swallowed and logged, and nothing else in the panel would mention it.
     */
    public function test_the_navigation_badge_counts_only_failing_active_endpoints(): void
    {
        OutgoingWebhook::factory()->create();

        $this->assertNull(OutgoingWebhookResource::getNavigationBadge());

        OutgoingWebhook::factory()->create(['consecutive_failures' => 3]);
        OutgoingWebhook::factory()->inactive()->create(['consecutive_failures' => 9]);

        $this->assertSame('1', OutgoingWebhookResource::getNavigationBadge());
    }
}
