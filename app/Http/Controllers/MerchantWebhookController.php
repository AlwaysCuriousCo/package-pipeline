<?php

namespace App\Http\Controllers;

use App\Enums\MerchantProvider;
use App\Jobs\ProcessMerchantEvent;
use App\Merchants\UnsupportedMerchantException;
use App\Merchants\Values\NormalisedEvent;
use App\Models\MerchantEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives a merchant's webhooks: verify, record, acknowledge, process later.
 *
 * The same discipline as the git-provider webhooks. The signature is checked
 * against the raw body before anything is believed; a verified delivery is
 * written to merchant_events *before* the 2xx goes out, so a deploy
 * mid-delivery loses nothing; and the unique event id makes a redelivery an
 * acknowledged no-op rather than a double-processed payment. Everything that
 * acts on the event happens on the queue, where it can retry without the
 * merchant re-signing anything.
 */
class MerchantWebhookController extends Controller
{
    public function __invoke(Request $request, string $merchant): Response
    {
        if (! config('registry.billing.enabled')) {
            abort(404);
        }

        $provider = MerchantProvider::tryFrom($merchant);

        if ($provider === null || ! $provider->receivesWebhooks()) {
            abort(404);
        }

        try {
            $event = $provider->client()->verifySignature($request);
        } catch (UnsupportedMerchantException) {
            // An invalid signature is indistinguishable from an attacker;
            // say only "no". 400 rather than 401: there is no credential to
            // challenge for, and merchants disable endpoints that 401.
            return response('Rejected.', 400);
        }

        if ($event->kind === NormalisedEvent::IGNORE) {
            // Verified, deliberately unrecorded: merchants emit dozens of
            // event types nobody subscribed to, and an inbox of them would
            // bury the rows an investigator actually looks for.
            return response()->noContent();
        }

        try {
            $stored = MerchantEvent::query()->create([
                'merchant' => $provider,
                'external_id' => $event->externalId,
                'type' => $event->type,
                'payload' => $event->payload,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A redelivery of something already recorded. Acknowledged so
            // the merchant stops retrying; not reprocessed.
            return response()->noContent();
        }

        ProcessMerchantEvent::dispatch($stored->getKey(), $event->kind, $event->occurredAt);

        return response()->noContent();
    }
}
