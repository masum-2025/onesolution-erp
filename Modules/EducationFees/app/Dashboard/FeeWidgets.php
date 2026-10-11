<?php

namespace Modules\EducationFees\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\EducationFees\Models\Concession;
use Modules\EducationFees\Models\Posting;
use Modules\EducationFees\Models\Receipt;
use Modules\EducationFees\Models\ReceiptVoid;
use Modules\EducationFees\Models\Refund;
use Modules\EducationFees\Services\FeeOffice;

/**
 * What waits for a second person (discounts, receipt voids, refunds): a
 * count on the dashboard, and in the bell for those who may decide and did
 * not ask themselves; fee events the books refused, for those who set fees up.
 */
final class FeeWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = FeePlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$company, $units] = $place;
        $office = app(FeeOffice::class);
        $count = $office->query(Concession::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')->count()
            + $office->query(ReceiptVoid::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')->count()
            + $office->query(Refund::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')->count();

        return WidgetData::stat($count, hint: __('education_fees::dashboard.waiting_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $place = FeePlace::of($context);
        if ($place === null) {
            return [];
        }
        [$company, $units] = $place;
        $unit = $context->organization();
        $office = app(FeeOffice::class);
        $me = $context->user()?->getKey();
        $items = [];
        if (Gate::allows('education_fees.approve', $unit)) {
            $discounts = $office->query(Concession::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('requested_by')->orWhere('requested_by', '!=', $me))->count();
            $items[] = $discounts === 0 ? null : new AttentionItem('education_fees.discounts_waiting', __('education_fees::dashboard.attention_discounts'), $discounts, '/fees/approvals', 'warn');
        }
        if (Gate::allows('education_fees.void', $unit)) {
            $mine = $office->query(Receipt::class, $company)->where('collected_by', $me)->pluck('id');
            $voids = $office->query(ReceiptVoid::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('requested_by')->orWhere('requested_by', '!=', $me))->whereNotIn('receipt_id', $mine)->count();
            $refunds = $office->query(Refund::class, $company)->whereIn('unit_id', $units)->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('requested_by')->orWhere('requested_by', '!=', $me))->count();
            $items[] = $voids + $refunds === 0 ? null : new AttentionItem('education_fees.voids_waiting', __('education_fees::dashboard.attention_voids'), $voids + $refunds, '/fees/approvals', 'warn');
        }
        if (Gate::allows('education_fees.configure', $company)) {
            $failed = $office->query(Posting::class, $company)->where('status', 'failed')->count();
            $items[] = $failed === 0 ? null : new AttentionItem('education_fees.postings_failed', __('education_fees::dashboard.attention_postings'), $failed, '/fees/books', 'bad');
        }

        return array_values(array_filter($items));
    }
}
