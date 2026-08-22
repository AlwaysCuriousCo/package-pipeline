<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Token;
use App\Services\Billing\SubscriptionTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Token management inside the customer area, inside the plan's cap.
 *
 * Only tokens issued under one of the customer's subscriptions are managed
 * here — a personal token made on the panel's ApiTokens page is somebody
 * else's story. The cap is enforced by SubscriptionTokens, the same path
 * the activation token took, so there is exactly one place it can be wrong.
 */
class SubscriptionTokenController extends Controller
{
    public function store(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);
        abort_unless($subscription->customer?->contact()?->is($request->user()) ?? false, 403);
        abort_unless($subscription->grantsAccess(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $token = app(SubscriptionTokens::class)->issueFor($subscription, $data['name']);

        if ($token === null) {
            return back()->with('status', 'Your plan\'s token limit is reached — revoke one first.');
        }

        return back()->with('plainToken', $token->plainText);
    }

    public function destroy(Request $request, Token $token): RedirectResponse
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);

        $subscription = $token->subscription;

        abort_unless($subscription?->customer?->contact()?->is($request->user()) ?? false, 403);

        $token->delete();

        return back()->with('status', 'Token revoked.');
    }
}
