<?php

namespace App\Platform\Billing\Services;

use App\Platform\Billing\Models\Invoice;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;

/**
 * Everything needed to issue one invoice or credit note. Lines are
 * [organization_id, plan_key, description {locale: text}, quantity, unit_amount_minor].
 */
final class InvoiceDraft
{
    /**
     * @param  list<array{organization_id: string|null, plan_key: string|null, description: array<string, string>, quantity: int, unit_amount_minor: int}>  $lines
     */
    public function __construct(
        public string $type,
        public string $billedTo,
        public Partner $partner,
        public ?Organization $organization,
        public Partner $brandPartner,
        public string $billingMode,
        public string $currency,
        public int $taxRateBp,
        public int $paymentTermsDays,
        public array $lines,
        public ?string $billingKey = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?Invoice $credits = null,
        public ?string $reason = null,
    ) {}
}
