<?php

namespace Tests\Feature;

use App\Enums\WebhookEvent;
use App\Filament\Resources\OutgoingWebhooks\OutgoingWebhookResource;
use App\Filament\Resources\OutgoingWebhooks\Pages\CreateOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\EditOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\ListOutgoingWebhooks;
use App\Jobs\DeliverWebhook;
use App\Models\OutgoingWebhook;
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
