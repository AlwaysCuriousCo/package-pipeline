<?php

namespace App\Http\Controllers\Billing;

use App\Enums\MerchantProvider;
use App\Http\Controllers\Controller;
use App\Models\MerchantEvent;
use App\Models\MerchantReference;
use App\Models\Repository;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where checkout lands: the purchase confirmed, and the activation token —
 * shown here exactly once, because a page the buyer is already looking at is
 * the only place a credential can be handed over without writing it down.
 *
 * The subscription row is created by the checkout.completed webhook, which
 * races this redirect and usually wins. When it has not arrived yet the page
 * says so and refreshes itself; nothing here talks to the merchant.
 */
class WelcomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);

        $sessionId = (string) $request->query('session', '');
        $subscription = $this->subscriptionForSession($sessionId);

        $plainToken = null;

        if ($subscription !== null
            && $subscription->customer?->contact()?->is($request->user())
            && $subscription->plan->auto_issue_token
            && ! $subscription->tokens()->exists()) {
            $plainToken = app(SubscriptionTokens::class)->issueActivationToken($subscription)?->plainText;
        }

        return response()->view('billing.welcome', [
            'repository' => Repository::default(),
            'subscription' => $subscription,
            'plainToken' => $plainToken,
            'registryUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    private function subscriptionForSession(string $sessionId): ?Subscription
    {
        if ($sessionId === '') {
            return null;
        }

        $merchant = MerchantProvider::tryFrom((string) config('registry.billing.merchant'));

        if ($merchant === null) {
            return null;
        }

        // A one-time purchase is mapped by its session id. A subscription
        // checkout's single reference slot holds the durable subscription id
        // instead, so the session is traced through the recorded event.
        $resolved = MerchantReference::resolve($merchant, $sessionId);

        if ($resolved instanceof Subscription) {
            return $resolved;
        }

        $payload = MerchantEvent::query()
            ->where('merchant', $merchant)
            ->where('payload->id', $sessionId)
            ->first()
            ?->payload;

        $subscriptionId = $payload['subscription'] ?? null;

        if (! is_string($subscriptionId)) {
            return null;
        }

        $resolved = MerchantReference::resolve($merchant, $subscriptionId);

        return $resolved instanceof Subscription ? $resolved : null;
    }
}
