<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Merchants\UnsupportedMerchantException;
use App\Models\BillingCustomer;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer area: subscription status, invoices, tokens, and the door to
 * the merchant's portal — everything a paying customer manages, none of it
 * inside /admin.
 *
 * A customer is a User with no role; the panel is structurally closed to
 * them and this is their whole surface. The pages show only what belongs to
 * the signed-in user's own billing customer (or the one their team holds and
 * names them contact for), which is the entire authorization story here.
 */
class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);

        $user = $request->user();
        $customer = $this->customerFor($user);

        return response()->view('billing.index', [
            'repository' => Repository::default(),
            'user' => $user,
            'customer' => $customer?->load([
                'subscriptions.plan',
                'subscriptions.price',
                'invoices' => fn ($query) => $query->orderByDesc('issued_at')->limit(24),
            ]),
            'tokens' => $customer !== null
                ? $user->tokens()->whereNotNull('subscription_id')->get()
                : collect(),
        ]);
    }

    /** One hop into the merchant's card-and-invoices portal, and back. */
    public function portal(Request $request): RedirectResponse
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);

        $customer = $this->customerFor($request->user());

        abort_if($customer === null, 404);

        try {
            return redirect()->away(
                $customer->client()->portalUrl($customer, route('billing.index')),
            );
        } catch (UnsupportedMerchantException $e) {
            return redirect()->route('billing.index')->with('status', $e->getMessage());
        }
    }

    /**
     * The signed-in user's billing customer: their own, or the team one that
     * names them as billing contact.
     */
    private function customerFor(?User $user): ?BillingCustomer
    {
        if ($user === null) {
            return null;
        }

        return $user->billingCustomer
            ?? BillingCustomer::query()
                ->where('billing_contact_user_id', $user->getKey())
                ->first();
    }
}
