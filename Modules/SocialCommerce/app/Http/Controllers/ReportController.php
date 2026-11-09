<?php

namespace Modules\SocialCommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\SalesReport;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * SC-5c: Facebook sales between two days (?from, ?to, ?page_id), for the
 * company in the address, with social_commerce.reports there (it shows
 * amounts). Read only, rate limited; the last 30 days by default.
 */
class ReportController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SocialBook $book, private SalesReport $report) {}

    public function sales(Request $request, string $organization): JsonResponse
    {
        $company = $this->book->companyOf($this->findVisible($organization));
        Gate::authorize('social_commerce.reports', $company);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page_id' => ['nullable', 'string', 'size:26'],
        ]);
        $today = CarbonImmutable::now($this->book->timezone($company));
        $to = $filters['to'] ?? $today->toDateString();
        $from = $filters['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= SalesReport::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('social_commerce::social.validation.report_range', ['days' => SalesReport::MAX_DAYS])]);
        }
        $pageId = $filters['page_id'] ?? null;
        if ($pageId !== null && ! $this->book->query(SocialPage::class, $company)->whereKey($pageId)->exists()) {
            throw SocialCommerceException::notFound('page');
        }

        return response()->json(['data' => $this->report->sales($company, $from, $to, $pageId)]);
    }
}
