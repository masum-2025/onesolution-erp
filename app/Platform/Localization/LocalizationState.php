<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Partners\HostContext;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;

/**
 * What the browser needs to speak the current context's language, sent with
 * the page and with /api/me:
 *
 *   languages  the ones people may pick here (published, narrowed by the rule i18n.languages)
 *   i18n.hash      fingerprint of the wording of this context's levels ("0" = none)
 *   i18n.overlays  languages with reworded texts: only those cost a request
 */
class LocalizationState
{
    public function __construct(
        private LanguageRegistry $languages,
        private ScopeChain $scopes,
        private TranslationOverlay $overlay,
        private CurrentContext $context,
        private HostContext $host,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
    ) {}

    /**
     * @return array{languages: list<array{code: string, name: string, direction: string, source: string, fallback: string|null}>, locales: list<string>, i18n: array{hash: string, overlays: list<string>}}
     */
    public function current(): array
    {
        $offered = $this->offered();
        $chain = $this->scopes->current();

        return [
            'languages' => array_map(fn (string $code) => array_intersect_key(
                $this->languages->get($code),
                array_flip(['code', 'name', 'direction', 'source', 'fallback']),
            ), $offered),
            'locales' => $offered,
            'i18n' => [
                'hash' => $this->overlay->hash($chain),
                'overlays' => $this->overlay->locales($chain, Channel::Ui),
            ],
        ];
    }

    /**
     * Published languages, narrowed to the rule's list where one is set
     * (unknown codes passed over; never left with none).
     *
     * @return list<string>
     */
    public function offered(): array
    {
        $published = $this->languages->published();
        $wanted = $this->rules->get('i18n.languages', $this->ruleContext());
        if (! is_array($wanted) || $wanted === []) {
            return $published;
        }

        $offered = array_values(array_intersect($published, $wanted));

        return $offered === [] ? $published : $offered;
    }

    private function ruleContext(): RuleContext
    {
        if ($this->context->hasOrganization()) {
            return $this->ruleContexts->forOrganization($this->context->organization(), ancestors: $this->context->ancestors()->values());
        }
        if ($this->context->hasPartnerConsole()) {
            return $this->ruleContexts->forPartner($this->context->partner());
        }
        if (($client = $this->host->client()) !== null) {
            return $this->ruleContexts->forOrganization($client);
        }
        if (($partner = $this->host->partner()) !== null) {
            return $this->ruleContexts->forPartner($partner);
        }

        return $this->ruleContexts->platform();
    }
}
