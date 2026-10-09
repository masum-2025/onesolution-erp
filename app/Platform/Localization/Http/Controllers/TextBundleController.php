<?php

namespace App\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\ScopeChain;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\ContextSource;
use App\Platform\Tenancy\Exceptions\TenancyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browser's reworded texts for the current context (signed in or not):
 *
 *   GET /api/i18n/{hash}/{locale}              every reworded text of a file language
 *   GET /api/i18n/{hash}/{locale}/{namespace}  one screen's texts of a database language
 *
 * The hash in the address is the one /api/me (or the page) gave; while it is
 * still current the answer is cached by the browser for good ("immutable"),
 * so a page load costs no request at all after the first. "private": a
 * company's wording is never kept by a shared cache.
 */
class TextBundleController extends Controller
{
    public function __construct(
        private LanguageRegistry $languages,
        private ScopeChain $scopes,
        private TranslationOverlay $overlay,
        private ContextSource $source,
        private ContextResolver $resolver,
    ) {}

    public function __invoke(Request $request, string $hash, string $locale, ?string $namespace = null): JsonResponse
    {
        if ($this->languages->get($locale) === null) {
            abort(404);
        }

        $this->enterContext($request);
        $chain = $this->scopes->current();
        $texts = $this->overlay->texts($chain, Channel::Ui, $locale, $namespace);
        $current = $this->overlay->hash($chain) === $hash;

        return response()
            ->json(['data' => (object) $texts])
            ->header('Cache-Control', $current ? 'private, max-age=31536000, immutable' : 'no-store')
            ->header('Vary', 'Cookie, Authorization');
    }

    /** The signed-in person's context, verified as on any request; nobody's otherwise. */
    private function enterContext(Request $request): void
    {
        $user = $request->user('sanctum');
        if ($user === null) {
            return;
        }

        try {
            if (($organizationId = $this->source->organizationId($request)) !== null) {
                $this->resolver->enterOrganization($user, $organizationId);
            } elseif (($partnerId = $this->source->partnerId($request)) !== null) {
                $this->resolver->enterPartner($user, $partnerId);
            } elseif (($grantId = $this->source->supportGrantId($request)) !== null) {
                $this->resolver->enterSupport($user, $grantId);
            }
        } catch (TenancyException) {
            // A context no longer valid: the address's wording only.
        }
    }
}
