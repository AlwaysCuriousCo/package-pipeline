<?php

namespace App\Http\Controllers;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The yes-or-no Caddy's on-demand TLS asks before requesting a certificate:
 * is this a host the registry answers at?
 *
 * Without it, anybody who points a name at the server has a certificate
 * issued for it, and enough of them run the registry into the CA's rate
 * limits. It says nothing DNS does not already say — each host it confirms is
 * one somebody already resolves to this server.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless((bool) config('registry.tls_ask'), 404);

        $domain = mb_strtolower((string) $request->query('domain', ''));

        $known = $domain === Repository::registryHost()
            || Repository::forHost($domain) instanceof Repository;

        return response('', $known ? 200 : 404);
    }
}
