<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\OutgoingWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An HTTP endpoint this registry posts to when something happens.
 *
 * The mirror image of the `webhook_*` columns on Package: those are how a VCS
 * provider tells this app a ref moved; this is how this app tells a deploy
 * pipeline, a chat tool that is not Slack, or an internal service that a
 * release landed or a sync died.
 *
 * It is an installation-wide subscription rather than a per-package one,
 * because what it subscribes to are facts about the registry. A consumer that
 * only cares about one package reads the name out of the payload — which is a
 * line of configuration on their side, against a row, a form and a scoping rule
 * on ours.
 *
 * @see docs/outgoing-webhooks.md
 * @see DeliverWebhook for the delivery and its retries
 */
#[Fillable(['name', 'url', 'secret', 'events', 'active'])]
class OutgoingWebhook extends Model
{
    /** @use HasFactory<OutgoingWebhookFactory> */
    use HasFactory, LogsAuditableChanges;

    /**
     * The column default alone leaves a freshly created row holding null in
     * memory, and the table's status badge reads it off the record the panel
     * just created.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'consecutive_failures' => 0,
    ];

    /**
     * Where deliveries go and what they are about. The delivery bookkeeping
     * below it is left out for the reason the trait describes: it is written by
     * a worker several times an hour and would bury the configuration changes
     * an investigator is actually looking for.
     *
     * The secret is excluded by the trait's second gate anyway; naming it here
     * would only invite someone to assume the list is what protects it.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'url', 'events', 'active'];
    }

    /**
     * For Slack, Teams and Discord the endpoint *is* the credential: anyone
     * holding that path can post as the integration, and the `secret` this
     * model takes such care to encrypt is not used by any of them. So the log
     * keeps where a webhook points and not the rest of it.
     *
     * `url` still stays audited rather than being dropped, because "a delivery
     * went somewhere it should not" is a change to this field and the origin is
     * most of the answer. A repoint within the same host records an entry whose
     * before and after read alike — which is the log saying it changed and
     * declining to say what to, rather than saying nothing at all.
     *
     * @return list<string>
     */
    protected function opaqueUrlAttributes(): array
    {
        return ['url'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    /**
     * The endpoints that should hear about this event.
     *
     * The events filter is applied in PHP rather than in SQL because "does this
     * JSON array contain this string" is spelled three different ways across
     * the three databases this app supports, and one of those spellings is a
     * full scan anyway. The table is a handful of rows an operator typed by
     * hand; the query that would be optimised here does not exist.
     *
     * @return Collection<int, self>
     */
    public static function listeningFor(WebhookEvent $event): Collection
    {
        return self::query()
            ->where('active', true)
            ->get()
            ->filter(fn (self $webhook): bool => $webhook->subscribesTo($event))
            ->values();
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], strict: true);
    }

    /**
     * The events this endpoint wants, as enum cases — dropping any value that
     * no longer names one, so a case removed in a later release degrades to
     * "this endpoint no longer subscribes to it" rather than to a crash on the
     * next fan-out.
     *
     * @return list<WebhookEvent>
     */
    public function subscribedEvents(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?WebhookEvent => is_string($value) ? WebhookEvent::tryFrom($value) : null,
            $this->events ?? [],
        )));
    }

    /**
     * Record that a delivery arrived and was accepted.
     *
     * Quietly, and that is the point: this runs on the queue after every
     * accepted delivery, and letting it move `updated_at` — or file an audit
     * entry — would turn the busiest endpoint in the registry into the noisiest
     * row in the activity log.
     */
    public function recordSuccess(int $status): void
    {
        $this->forceFill([
            'last_delivered_at' => now(),
            'last_status' => $status,
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->saveQuietly();
    }

    /**
     * Record that a delivery did not arrive, or arrived and was refused.
     *
     * The counter is incremented rather than set, because the question an
     * operator asks of a red endpoint is "has this been broken all week or did
     * it blink?" and one status code cannot answer it.
     */
    public function recordFailure(?int $status, string $reason): void
    {
        $this->forceFill([
            'last_delivered_at' => now(),
            'last_status' => $status,
            'last_error' => $reason,
            'consecutive_failures' => $this->consecutive_failures + 1,
        ])->saveQuietly();
    }

    /**
     * Whether the last delivery got through. Null when none has been attempted,
     * which is not the same as failing and must not read as either.
     */
    public function healthy(): ?bool
    {
        return $this->last_delivered_at === null ? null : $this->consecutive_failures === 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFailing(Builder $query): Builder
    {
        return $query->where('consecutive_failures', '>', 0);
    }
}
