<?php

namespace App\Services\Billing;

use App\Enums\MerchantProvider;
use App\Merchants\Values\CheckoutRequest;
use App\Merchants\Values\CheckoutSession;
use App\Models\BillingCustomer;
use App\Models\PlanPrice;
use App\Models\Team;
use App\Models\User;

/**
 * Turns "this account wants to buy this price" into a merchant checkout.
 *
 * The one place a BillingCustomer is created for a first-time buyer, and the
 * one place the checkout's success/cancel URLs are decided — so the welcome
 * page always receives the session id it needs to find (or finish) the
 * subscription the payment produced.
 */
final class CheckoutBuilder
{
    /**
     * Begin a checkout for the account, creating its billing customer on
     * first purchase.
     */
    public function start(User|Team $billable, PlanPrice $price, ?User $contact = null): CheckoutSession
    {
        $merchant = MerchantProvider::from((string) config('registry.billing.merchant'));
        $customer = $this->customerFor($billable, $merchant, $contact);

        $client = $merchant->client();

        // First purchase of this plan through this merchant syncs it there;
        // afterwards the sync is a fast no-op convergence check.
        (new CatalogSync)->syncPlan($price->plan, $merchant);

        $session = $client->startCheckout(new CheckoutRequest(
            customer: $customer,
            price: $price,
            quantity: 1,
            couponCode: null,
            successUrl: route('billing.welcome').'?session={CHECKOUT_SESSION_ID}',
            cancelUrl: route('pages.pricing'),
            collectTax: $merchant->supportsTax(),
            collectBusinessDetails: true,
        ));

        return $session;
    }

    /**
     * The account's billing customer at this merchant, created on first use
     * with the identity fields checkout will refine.
     */
    public function customerFor(User|Team $billable, MerchantProvider $merchant, ?User $contact = null): BillingCustomer
    {
        $existing = $billable->billingCustomer;

        if ($existing !== null && $existing->merchant === $merchant) {
            return $existing;
        }

        $contact ??= $billable instanceof User ? $billable : null;

        $customer = new BillingCustomer([
            'name' => $billable->name,
            'email' => (string) $contact?->email,
            'merchant' => $merchant,
            'billing_contact_user_id' => $billable instanceof Team ? $contact?->getKey() : null,
        ]);

        $customer->billable()->associate($billable);
        $customer->save();

        if ($merchant !== MerchantProvider::Manual) {
            $customer->forceFill([
                'merchant_customer_id' => $merchant->client()->ensureCustomer($customer),
            ])->save();
        }

        return $customer;
    }
}
