<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleScope;

/**
 * One step of a resolution chain, e.g. (company, 01J...) or (plan, "business").
 */
final readonly class RuleLevel
{
    public function __construct(
        public RuleScope $scope,
        public ?string $scopeId,
        public ?string $name = null,
    ) {}

    public function is(RuleScope $scope, ?string $scopeId): bool
    {
        return $this->scope === $scope && $this->scopeId === $scopeId;
    }
}
