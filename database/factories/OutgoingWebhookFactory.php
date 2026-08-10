<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\OutgoingWebhook;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutgoingWebhook>
 */
class OutgoingWebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Deploy pipeline',
            'url' => 'https://ci.example.com/hooks/registry',
            'secret' => 'shhh',
            // Everything an endpoint may actually subscribe to. Not cases(),
            // which includes the ping the panel sends by hand and no endpoint
            // can ask for.
            'events' => array_column(WebhookEvent::subscribable(), 'value'),
            'active' => true,
        ];
    }

    /**
     * Subscribed to exactly these events and no others.
     */
    public function subscribedTo(WebhookEvent ...$events): static
    {
        return $this->state(fn (array $attributes): array => [
            'events' => array_column($events, 'value'),
        ]);
    }

    /**
     * Confined to one Composer repository. The default is the unscoped row an
     * upgrade leaves behind, because that is what most of the suite is about;
     * this is the one an operator on a multi-team registry should be creating.
     */
    public function scopedTo(Repository $repository): static
    {
        return $this->state(fn (array $attributes): array => [
            'repository_id' => $repository->getKey(),
        ]);
    }

    /**
     * Configured but switched off, which is how an operator silences an
     * endpoint without losing its URL and secret.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    /**
     * No shared secret, so deliveries go unsigned — the shape an endpoint on a
     * trusted network is sometimes configured in.
     */
    public function unsigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'secret' => null,
        ]);
    }
}
