<?php

namespace App\Http\Controllers;

use App\Auth\PasswordSetupLink;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Trades a sealed setup link for a session claim, then sends the browser on
 * to the panel's reset form with an empty query string — see
 * {@see PasswordSetupLink} for why the address and token must not be in the
 * URL the user's browser is asked to visit.
 */
class PasswordSetupController extends Controller
{
    public function __invoke(Request $request, string $payload): RedirectResponse
    {
        $claim = PasswordSetupLink::open($payload);

        $panel = Filament::getPanel('admin');

        if ($claim === null) {
            Notification::make()
                ->title('That password link is no longer valid.')
                ->body(sprintf('Links expire %d minutes after being issued. Ask an administrator for a fresh one.', PasswordSetupLink::TTL_MINUTES))
                ->danger()
                ->send();

            return redirect()->to($panel->getLoginUrl());
        }

        $request->session()->put(PasswordSetupLink::SESSION_KEY, $claim);

        return redirect()->to(route($panel->generateRouteName('auth.password-reset.set')));
    }
}
