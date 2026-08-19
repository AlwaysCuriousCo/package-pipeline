<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\PlanPrice;
use App\Services\Billing\CheckoutBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The buy button: hands the signed-in user to the merchant's hosted
 * checkout for one price.
 *
 * The one gate beyond "the plan is on sale" is a verified email for
 * self-registered accounts — the address is where the receipt, the dunning
 * notices and the trial warning go, and a fabricated one must not start a
 * trial. Accounts an administrator or SSO created carry a role or an
 * external identity and were never anonymous.
 */
class CheckoutController extends Controller
{
    public function store(Request $request, PlanPrice $price): RedirectResponse
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);
        abort_unless($price->active && $price->plan->purchasable(), 404);

        $user = $request->user();

        if ($user->email_verified_at === null && ! $user->roles()->exists()) {
            return redirect()->route('billing.index')
                ->with('status', 'Verify your email address before buying — the link is in your inbox.');
        }

        $session = app(CheckoutBuilder::class)->start($user, $price);

        abort_if($session->redirectUrl === null, 500);

        return redirect()->away($session->redirectUrl);
    }
}
