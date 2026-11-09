<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\SocialCommerce\Services\Pages;

/**
 * Where Meta sends a person back after signing in (public: the one-time
 * state is the proof, and it is bound to the person and the company). The
 * page tokens stay on the server; the browser goes back to the app, which
 * shows the pages found.
 */
class FacebookCallbackController extends Controller
{
    public function __construct(private Pages $pages) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $next = strlen($state) === 48
            ? $this->pages->callback($state, $request->filled('code') ? (string) $request->query('code') : null, $request->filled('error') ? (string) $request->query('error') : null)
            : '/social/pages?connect=expired';

        return redirect($next)->header('Referrer-Policy', 'no-referrer');
    }
}
