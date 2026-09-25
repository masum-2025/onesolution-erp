<?php

namespace App\Platform\Rules;

/**
 * The chain of levels a rule is resolved through, most general first, plus the
 * country used for country-specific rules. The last level is the target.
 */
final readonly class RuleContext
{
    /**
     * @param  list<RuleLevel>  $levels
     */
    public function __construct(
        public array $levels,
        public ?string $countryCode,
        public ?string $partnerId = null,
        public ?string $rootOrganizationId = null,
    ) {}

    public function target(): RuleLevel
    {
        return $this->levels[array_key_last($this->levels)];
    }

    public function targetIndex(): int
    {
        return array_key_last($this->levels);
    }

    /** Stable identity of this chain, for cache keys. */
    public function fingerprint(): string
    {
        return sha1(implode('|', array_map(
            fn (RuleLevel $level) => $level->scope->value.':'.$level->scopeId,
            $this->levels,
        )).'|'.$this->countryCode);
    }
}
