<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Partners\HostContext;
use App\Platform\Tenancy\Context\CurrentContext;

/**
 * Server texts (messages, emails) as the current request's levels word them.
 * Kept for one request or job (scoped): the chain is worked out once per
 * context, the texts once per language.
 */
class ServerOverlay
{
    /** @var array<string, list<TranslationScope>> */
    private array $chains = [];

    /** @var array<string, array<string, string>> */
    private array $texts = [];

    public function __construct(
        private CurrentContext $context,
        private HostContext $host,
        private ScopeChain $scopes,
        private TranslationOverlay $overlay,
        private LanguageRegistry $languages,
    ) {}

    public function text(string $locale, string $key): ?string
    {
        $identity = $this->identity();
        $memo = "{$identity}|{$locale}";

        if (! isset($this->texts[$memo])) {
            $chain = $this->chains[$identity] ??= $this->scopes->current();
            $this->texts[$memo] = in_array($locale, $this->overlay->locales($chain, Channel::Server), true)
                ? $this->overlay->texts($chain, Channel::Server, $locale)
                : [];
        }

        return $this->texts[$memo][$key] ?? null;
    }

    /** The file language a database language falls back to; null for file languages. */
    public function fallbackOf(string $locale): ?string
    {
        $language = $this->languages->get($locale);

        return $language === null || $language['source'] === 'file' ? null : $language['fallback'];
    }

    /** Who is being served: an organization, a partner console, or an address. */
    private function identity(): string
    {
        if ($this->context->hasOrganization()) {
            return 'o:'.$this->context->organization()->getKey();
        }
        if ($this->context->hasPartnerConsole()) {
            return 'p:'.$this->context->partner()->getKey();
        }

        return 'h:'.($this->host->partner()?->getKey() ?? '').':'.($this->host->clientRootId() ?? '');
    }
}
