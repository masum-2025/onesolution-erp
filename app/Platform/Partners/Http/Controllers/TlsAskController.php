<?php

namespace App\Platform\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Partners\Services\HostResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy's on-demand TLS "ask": 200 lets Caddy fetch a certificate for the
 * host, anything else refuses. Only platform hosts and DNS-verified active
 * partner domains pass, so nobody can make the server request certificates
 * for arbitrary names. Needs the shared token (TLS_ASK_TOKEN).
 *
 *   on_demand_tls { ask http://127.0.0.1/internal/tls/ask?token=SECRET }
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request, HostResolver $hosts): Response
    {
        $token = (string) config('branding.tls_ask_token');
        $domain = strtolower((string) $request->query('domain'));

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            return response('', 403);
        }

        $allowed = $domain !== '' && ($hosts->isPlatformHost($domain) || $hosts->activeDomain($domain) !== null);

        return response('', $allowed ? 200 : 404);
    }
}
