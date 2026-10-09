<?php

namespace App\Http\Middleware;

use App\Platform\Localization\LanguageRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language the user picked in the app, sent as X-Locale. Only supported
 * locales are accepted; anything else is ignored. An explicit choice wins over
 * the organization's default language (see ResolveOrganization).
 */
class ApplyRequestLocale
{
    private const CHOSEN = 'locale_chosen_by_user';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('X-Locale');

        if (is_string($locale) && in_array($locale, LanguageRegistry::codes(), true)) {
            app()->setLocale($locale);
            $request->attributes->set(self::CHOSEN, true);
        }

        return $next($request);
    }

    public static function wasChosen(Request $request): bool
    {
        return $request->attributes->get(self::CHOSEN) === true;
    }
}
