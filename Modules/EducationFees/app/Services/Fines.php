<?php

namespace Modules\EducationFees\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Models\BillLine;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Models\Fine;

/**
 * Late fines under the rule education_fees.late_fine (per campus): each
 * night an open bill past its due date and grace days gets what it should
 * carry by then (once, per day or per month, up to the most), as new rows,
 * never changed. Only bills with a head that takes fines. Someone with
 * education_fees.approve waives a bill's fine with a reason: a negative row,
 * and no more fines on that bill.
 */
class Fines
{
    public function __construct(private FeeOffice $office, private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /** Fines due today on the institution's open bills; how many bills got one. */
    public function apply(Organization $company): int
    {
        $today = $this->office->today($company)->toDateString();
        $bills = $this->office->query(Bill::class, $company)->where('status', 'open')->where('due_date', '<', $today)->whereNull('fines_stopped_at')
            ->whereColumn('paid_minor', '<', 'total_minor')->get();
        if ($bills->isEmpty()) {
            return 0;
        }
        $fining = $this->office->query(FeeHead::class, $company)->where('late_fine', true)->pluck('id');
        $withFines = $this->office->query(BillLine::class, $company)->whereIn('bill_id', $bills->pluck('id'))->whereIn('head_id', $fining)->distinct()->pluck('bill_id')->flip();
        $rules = [];
        $count = 0;
        foreach ($bills as $bill) {
            if (! isset($withFines[$bill->getKey()])) {
                continue;
            }
            $rules[$bill->unit_id] ??= (array) $this->rules->get('education_fees.late_fine', $this->contexts->forOrganization(Organization::query()->find($bill->unit_id) ?? $company));
            $target = FeeMath::fineBy($rules[$bill->unit_id], $bill->due_date->toDateString(), $today);
            $count += $this->raise($company, $bill, $target, $today) ? 1 : 0;
        }

        return $count;
    }

    /** No more fines on the bill; what it carries is taken off. */
    public function waive(Organization $company, Bill $bill, string $reason, int $baseVersion, User $actor): Bill
    {
        return $this->office->transaction($company, function () use ($company, $bill, $reason, $baseVersion, $actor) {
            $bill = $this->locked($company, $bill);
            if ($bill->version !== $baseVersion) {
                throw FeeException::versionConflict(['version' => $bill->version]);
            }
            if ($bill->fine_minor <= 0 || ! in_array($bill->status, ['open', 'paid'], true)) {
                throw FeeException::noFineToWaive();
            }
            $waived = $bill->fine_minor;
            (new Fine)->fill([
                'organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), 'kind' => 'waiver', 'amount_minor' => -$waived,
                'applied_on' => $this->office->today($company)->toDateString(), 'reason' => $reason, 'created_by' => $actor->getKey(),
            ])->save();
            $bill->forceFill([
                'fine_minor' => 0, 'total_minor' => $bill->total_minor - $waived, 'fines_stopped_at' => now(), 'version' => $bill->version + 1,
                'status' => $bill->paid_minor >= $bill->total_minor - $waived ? 'paid' : $bill->status,
            ])->save();
            $this->audit->record('education_fees.fine_waived', $bill, old: ['fine_minor' => $waived], new: ['fine_minor' => 0, 'total_minor' => $bill->total_minor], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $bill;
        });
    }

    private function raise(Organization $company, Bill $bill, int $target, string $today): bool
    {
        return $this->office->transaction($company, function () use ($company, $bill, $target, $today) {
            $bill = $this->locked($company, $bill);
            if ($bill->status !== 'open' || $bill->fines_stopped_at !== null) {
                return false;
            }
            $carried = (int) $this->office->query(Fine::class, $company)->where('bill_id', $bill->getKey())->where('kind', 'fine')->sum('amount_minor');
            $more = $target - $carried;
            if ($more <= 0 || $this->office->query(Fine::class, $company)->where('bill_id', $bill->getKey())->where('kind', 'fine')->where('applied_on', $today)->exists()) {
                return false;
            }
            (new Fine)->fill(['organization_id' => $company->getKey(), 'bill_id' => $bill->getKey(), 'kind' => 'fine', 'amount_minor' => $more, 'applied_on' => $today])->save();
            $bill->forceFill(['fine_minor' => $bill->fine_minor + $more, 'total_minor' => $bill->total_minor + $more, 'version' => $bill->version + 1])->save();
            $this->audit->record('education_fees.fine_added', $bill, new: ['fine_minor' => $bill->fine_minor, 'added_minor' => $more], organizationId: $company->getKey());

            return true;
        });
    }

    private function locked(Organization $company, Bill $bill): Bill
    {
        /** @var Bill $fresh */
        $fresh = $this->office->query(Bill::class, $company)->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

        return $fresh;
    }
}
