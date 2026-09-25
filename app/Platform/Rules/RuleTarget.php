<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;

/**
 * Where a rule value is written: one scope, with the chain above it.
 */
final readonly class RuleTarget
{
    public function __construct(
        public RuleScope $scope,
        public ?string $scopeId,
        public RuleContext $context,
        public ?Organization $organization = null,
        public ?Partner $partner = null,
    ) {}

    public function name(): string
    {
        return $this->context->target()->name ?? $this->scope->value;
    }

    public function partnerId(): ?string
    {
        return $this->partner?->getKey() ?? $this->organization?->partner_id;
    }
}
