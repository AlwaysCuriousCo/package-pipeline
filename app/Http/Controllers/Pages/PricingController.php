<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Repository;
use Illuminate\Http\Response;

/**
 * The public pricing page: every listed plan, and a page per plan.
 *
 * Anonymous like the package pages, on the same layout, for the same reason —
 * the plan a package's page names should be a link that answers. Only plans
 * marked `listed` appear; an unlisted plan's page still answers by slug, so a
 * negotiated tier can be sent as a link without being advertised.
 */
class PricingController extends Controller
{
    public function index(): Response
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);

        $plans = Plan::query()
            ->listed()
            ->with(['prices' => fn ($query) => $query->where('active', true)->orderByDesc('default')->orderBy('amount')])
            ->get();

        return response()->view('pages.pricing', [
            'repository' => Repository::default(),
            'plans' => $plans,
            'canonical' => route('pages.pricing'),
        ]);
    }

    public function show(Plan $plan): Response
    {
        abort_unless((bool) config('registry.billing.enabled'), 404);
        abort_unless($plan->active, 404);

        return response()->view('pages.plan', [
            'repository' => Repository::default(),
            'plan' => $plan->load([
                'prices' => fn ($query) => $query->where('active', true)->orderByDesc('default')->orderBy('amount'),
                'packages',
                'repositories',
            ]),
            'canonical' => route('pages.pricing.plan', $plan),
        ]);
    }
}
