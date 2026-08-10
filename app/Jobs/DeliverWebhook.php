<?php

namespace App\Jobs;

use App\Enums\WebhookEvent;
use App\Models\OutgoingWebhook;
use App\Support\EgressPolicy;
use App\Support\EgressProfile;
use App\Support\EgressRefused;
use App\Support\HttpTimeouts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST one event to one endpoint.
 *
 * Every property of this job exists to keep somebody else's HTTP endpoint out
 * of this registry's critical path. It is queued, so a sync never waits on it;
 * it swallows its own failures, so a dead endpoint never fails the job that
 * caused it; and it is dispatched once per endpoint, so a slow endpoint delays
 * only its own delivery.
 *
 * @see docs/outgoing-webhooks.md
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts against a receiver having a bad minute. Past that the
     * endpoint is not having a minute, it is down, and the operator needs to
     * see that in the panel rather than have this app keep knocking.
     */
    public int $tries = 3;

    /**
     * Comfortably outside the connect-plus-read budget the request itself is
     * given (HttpTimeouts::CONNECT + ::API), so a hung receiver is cut off by
     * the HTTP client — which lands in the catch below and is recorded — rather
     * than by the worker killing the job mid-request and leaving the endpoint
     * looking untried.
     */
    public int $timeout = 60;

    /**
     * An endpoint deleted while a delivery was queued is not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public OutgoingWebhook $webhook,
        public WebhookEvent $event,
        public array $payload,
        /**
         * Minted at dispatch rather than at delivery, so every retry of one
         * event carries the same id — which is what makes a receiver's
         * de-duplication work, and what lets it be matched against this app's
         * log when a delivery is disputed.
         */
        public string $delivery = '',
    ) {
        $this->delivery = $delivery === '' ? (string) Str::uuid7() : $delivery;
    }

    /**
     * Spread the retries out. A receiver that is redeploying is typically back
     * within a minute, and hammering it while it is not helps nobody.
     *
     * Applied by this job releasing itself rather than by the framework: a
     * delivery failure is never rethrown (see recordRefusal() for why), so the
     * automatic retry this would otherwise configure never runs.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        // Re-read rather than trusted from the serialized model: an operator
        // who switched an endpoint off is entitled to have that stop the
        // deliveries already sitting on the queue.
        if (! $this->webhook->fresh()?->active) {
            return;
        }

        $egress = EgressPolicy::for(EgressProfile::Webhook);

        try {
            // Judged before anything is spent reaching it, so an endpoint that
            // was never deliverable says so in the panel in those words rather
            // than as a connection error — and so it is not retried twice, since
            // no amount of waiting makes 10.0.0.1 a public address.
            $egress->addressesFor($this->webhook->url);
        } catch (EgressRefused $refusal) {
            $this->recordRefusal($refusal->getMessage(), retryable: false);

            return;
        }

        $body = $this->body();

        try {
            $response = Http::withHeaders($this->headers($body))
                ->connectTimeout(HttpTimeouts::CONNECT)
                ->timeout(HttpTimeouts::API)
                // Stated rather than left to Guzzle's defaults, which are the
                // same five hops but say nothing about which schemes may be
                // redirected to. Every hop is a destination the *receiver*
                // chose, which is why the check above is not the enforcement:
                // the middleware below sits under the redirect handler and
                // judges each one on its own.
                ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https']]])
                // Nothing is vouched for. The mirror exempts an upstream's own
                // origin because an operator configured that upstream in the
                // environment this app was deployed into; here the URL is the
                // input under suspicion, so there is nothing an exemption could
                // honestly be based on. WEBHOOK_PRIVATE_HOSTS is where an
                // internal endpoint is allowed, and the policy reads it itself.
                ->withMiddleware($egress->middleware(fn (string $url): bool => false))
                ->withBody($body, 'application/json')
                ->post($this->webhook->url);
        } catch (Throwable $exception) {
            $this->recordRefusal($exception->getMessage());

            return;
        }

        if ($response->successful()) {
            $this->webhook->recordSuccess($response->status());

            return;
        }

        $this->recordRefusal($this->refusal($response), $response->status());
    }

    /**
     * The exact bytes that are signed and sent.
     *
     * Encoded once and kept, because the signature has to be over what is
     * actually transmitted. Encoding a second time for the request would work
     * until some future PHP or Laravel version orders a key differently, at
     * which point every receiver would start rejecting every delivery — and the
     * app doing the signing would be the last place anyone looked. This is the
     * same reason GitHubWebhookController hashes `getContent()` rather than the
     * decoded payload re-encoded.
     */
    private function body(): string
    {
        return json_encode([
            'event' => $this->event->value,
            'delivery' => $this->delivery,
            'sent_at' => now()->toIso8601String(),
            'registry' => config('app.name'),
            'data' => $this->payload,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The headers a receiver authenticates and de-duplicates on.
     *
     * Named and shaped after the deliveries this app *accepts*, so a team that
     * has already written a GitHub webhook receiver has written most of this
     * one. `X-Hub-Signature-256: sha256=<hmac>` is GitHub's own spelling, and
     * it is what GitHubWebhookController checks on the way in — an operator
     * verifying a delivery from here uses the same three lines of code.
     *
     * The event and delivery headers are this app's own, namespaced, because
     * they are this app's facts: an `X-GitHub-Event` of `version.published`
     * would be a lie about who sent it.
     *
     * @return array<string, string>
     */
    private function headers(string $body): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'PackagePipeline-Webhook',
            'X-Package-Pipeline-Event' => $this->event->value,
            'X-Package-Pipeline-Delivery' => $this->delivery,
        ];

        $secret = $this->webhook->secret;

        // An endpoint with no secret is sent an unsigned delivery rather than
        // one signed with an empty string, which would look verifiable and
        // verify nothing.
        if (filled($secret)) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, (string) $secret);
        }

        return $headers;
    }

    /**
     * What a refusing receiver said, cut to something a panel column can hold.
     */
    private function refusal(Response $response): string
    {
        $body = Str::limit(Str::squish($response->body()), 200);

        return "Responded {$response->status()}".($body === '' ? '.' : ": {$body}");
    }

    /**
     * Record the failure and let the attempt end.
     *
     * This is the whole of the promise that a dead endpoint cannot hurt the
     * registry: nothing is rethrown, so the job succeeds, no `failed_jobs` row
     * accumulates, and the sync that caused the event never learns that
     * somebody's webhook receiver is down. What is left behind is a red row in
     * the panel, which is the correct place for that news.
     *
     * The exception to that is a retry: releasing the job back is how the
     * remaining attempts happen at all, and a released job is not a failed one.
     *
     * Deliberately not named `failed`. Laravel finds a job's failure hook with
     * `method_exists`, which answers true for a private method, and then calls
     * it from outside the class — so a method of that name here would turn any
     * genuine failure (a timeout, exhausted attempts, a row deleted from under
     * a queued delivery) into a fatal inside the worker, and the signature
     * would not have taken the Throwable it was handed either.
     *
     * `$retryable` is false for the one failure that is a fact about the
     * endpoint rather than about its health: a destination this app will not
     * dial is refused identically on all three attempts.
     */
    private function recordRefusal(string $reason, ?int $status = null, bool $retryable = true): void
    {
        $this->webhook->recordFailure($status, $reason);

        Log::warning('An outgoing webhook delivery failed.', [
            'webhook' => $this->webhook->name,
            // The endpoint itself, not its URL: for Slack, Teams and most chat
            // receivers the URL *is* the credential, and application logs are
            // routinely shipped somewhere with a wider readership than the
            // panel that already shows this failure.
            'endpoint' => $this->webhook->getKey(),
            'event' => $this->event->value,
            'delivery' => $this->delivery,
            'attempt' => $this->attempts(),
            'reason' => $reason,
        ]);

        if ($retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 120);
        }
    }
}
