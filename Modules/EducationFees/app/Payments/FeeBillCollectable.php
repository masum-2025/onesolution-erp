<?php

namespace Modules\EducationFees\Payments;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Payments\CollectionDue;
use App\Platform\Payments\Contracts\CollectableProvider;
use App\Platform\Payments\Models\Payment;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Services\Collections;
use Modules\EducationFees\Services\FeeOffice;

/**
 * Families pay a fee bill online (online_payments, the institution's own
 * merchant account): the bill's whole balance, only by someone the
 * institution linked in the portal to the bill's student (the student, a
 * parent), while it allows online payment (rule client_portal.allow_online_payment).
 * Confirmed money becomes a receipt (method "online") on that bill; if the
 * counter took some meanwhile, the rest waits as the student's advance.
 */
class FeeBillCollectable implements CollectableProvider
{
    public const KEY = 'education_fees.bill';

    public function __construct(
        private FeeOffice $office,
        private Collections $collections,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(?string $locale = null): string
    {
        return __('education_fees::fees.collectable', [], $locale);
    }

    public function due(Organization $organization, string $id, User $payer): ?CollectionDue
    {
        if (! $this->modules->isEnabled('education_fees', $organization)
            || ! (bool) $this->rules->get('client_portal.allow_online_payment', $this->contexts->forOrganization($organization))) {
            return null;
        }
        $company = $this->office->companyOf($organization);
        $bill = $this->office->query(Bill::class, $company)->whereKey($id)->where('status', 'open')->first();
        if ($bill === null || $bill->balanceMinor() <= 0 || ! $this->linked($organization, $bill, $payer)) {
            return null;
        }

        return new CollectionDue($bill->balanceMinor(), $bill->currency);
    }

    public function paid(Payment $payment): void
    {
        $organization = Organization::query()->findOrFail($payment->organization_id);
        $company = $this->office->companyOf($organization);
        /** @var Bill $bill */
        $bill = $this->office->query(Bill::class, $company)->findOrFail($payment->subject_id);
        $amount = (int) $payment->amount_minor;
        $toBill = $bill->status === 'open' ? min($amount, $bill->balanceMinor()) : 0;

        $this->collections->collect($company, [
            'student_id' => $bill->student_id, 'unit_id' => $bill->unit_id, 'amount_minor' => $amount, 'method' => 'online',
            'reference' => mb_substr((string) ($payment->gateway_ref ?? $payment->getKey()), 0, 100), 'received_on' => $this->office->today($company)->toDateString(),
            'op_id' => 'payment-'.$payment->getKey(), 'allocations' => $toBill > 0 ? [['bill_id' => $bill->getKey(), 'amount_minor' => $toBill]] : [],
        ], null, $payment->getKey());
    }

    public function returnPath(Payment $payment): string
    {
        $organization = Organization::query()->find($payment->organization_id);
        $bill = $organization === null ? null : $this->office->query(Bill::class, $this->office->companyOf($organization))->find($payment->subject_id);
        $membership = $bill === null ? null : OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $payment->user_id)->first();
        $link = $membership === null ? null : PortalLink::query()->where('membership_id', $membership->getKey())->where('subject_type', 'education.student')
            ->where('subject_id', $bill->student_id)->where('status', PortalLink::ACTIVE)->value('id');

        return $link === null ? '/portal' : "/portal/fees/{$link}?paid={$payment->getKey()}";
    }

    /** The payer's own portal membership here is linked to the bill's student (any relation). */
    private function linked(Organization $organization, Bill $bill, User $payer): bool
    {
        $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $payer->getKey())
            ->where('membership_type', MembershipType::Portal->value)->first();

        return $membership !== null && $membership->isActive() && PortalLink::query()
            ->where('membership_id', $membership->getKey())->where('organization_id', $organization->getKey())
            ->where('subject_type', 'education.student')->where('subject_id', $bill->student_id)->where('status', PortalLink::ACTIVE)
            ->exists();
    }
}
