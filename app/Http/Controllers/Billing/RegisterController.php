<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Public self-serve signup — the one registration path this app has, and it
 * exists only to buy things.
 *
 * Deliberately narrow. The account it creates holds no role, which is what
 * keeps it out of /admin entirely (User::canAccessPanel asks for one), no
 * grants, and no access to anything a subscription has not granted. Gated
 * behind BILLING_PUBLIC_SIGNUP on top of BILLING_ENABLED, because plenty of
 * registries sell to accounts that already exist without wanting an open
 * registration form on the internet.
 *
 * The honeypot field is the cheap half of abuse control; the throttle on the
 * POST is the other. Email verification is required before checkout — see
 * CheckoutController — so a mistyped or fabricated address cannot buy.
 */
class RegisterController extends Controller
{
    public function show(): Response
    {
        $this->gate();

        return response()->view('billing.register', [
            'repository' => Repository::default(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->gate();

        // The honeypot: a field humans never see and bots dutifully fill.
        if ($request->filled('website')) {
            return redirect()->route('billing.index');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->notify(new \App\Notifications\Billing\VerifyBillingEmail(
            URL::temporarySignedRoute('billing.verify', now()->addDay(), ['user' => $user->getKey()]),
        ));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('billing.index')
            ->with('status', 'Check your inbox — buying needs a verified address.');
    }

    public function verify(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->route('billing.index')->with('status', 'Email verified.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $user->email_verified_at === null) {
            $user->notify(new \App\Notifications\Billing\VerifyBillingEmail(
                URL::temporarySignedRoute('billing.verify', now()->addDay(), ['user' => $user->getKey()]),
            ));
        }

        return back()->with('status', 'Verification email sent.');
    }

    private function gate(): void
    {
        abort_unless(
            (bool) config('registry.billing.enabled') && (bool) config('registry.billing.public_signup'),
            404,
        );
    }
}
