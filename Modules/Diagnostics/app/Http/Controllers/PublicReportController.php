<?php

namespace Modules\Diagnostics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Modules\Diagnostics\Exceptions\DiagnosticsException;
use Modules\Diagnostics\Services\ReportLinks;
use Modules\Diagnostics\Services\ReportView;

/**
 * A report opened from its link: no sign-in, throttled per address. Only
 * verified values, the patient's name, number, age and sex, the referring
 * doctor and who signed; never notes, samples, bills or ids. An unknown
 * company, a wrong token and Diagnostics (or Patients) switched off all get
 * the same "not found"; an old link is "expired" (403).
 */
class PublicReportController extends Controller
{
    public function __invoke(string $organization, string $token, ReportLinks $links, ReportView $view, ModuleResolver $modules): JsonResponse
    {
        $company = preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $organization) === 1 ? Organization::query()->find($organization) : null;
        $on = $company !== null && collect(['patients', 'patient_billing', 'diagnostics'])->every(fn (string $key) => $modules->isEnabled($key, $company));
        if (! $on || ! in_array($company->type, [OrganizationType::Company, OrganizationType::Personal], true)) {
            throw DiagnosticsException::notFound('report');
        }
        $order = $links->open($company, $token);
        $report = $view->full($company, $order, verifiedOnly: true);
        $brand = app(BrandResolver::class)->for($company->partner, $company);

        return response()->json(['data' => [
            'number' => $report['number'],
            'created_at' => $report['created_at'],
            'organization' => $company->displayName(),
            'branch' => Organization::query()->find($order->unit_id)?->displayName(),
            'logo_url' => $brand['logo_url'] ?? null,
            'patient' => $report['patient'] === null ? null : array_intersect_key($report['patient'], array_flip(['mrn', 'name', 'sex', 'age'])),
            'referrer' => $report['referrer'] === null ? null : ['name' => $report['referrer']['name']],
            'tests' => collect($report['tests'])->filter(fn (array $line) => $line['amendment'] > 0)->map(fn (array $line) => [
                'id' => $line['id'], 'name' => $line['name'], 'amended' => $line['amended'], 'amendment' => $line['amendment'],
                'verified_by_name' => $line['verified_by_name'], 'verified_at' => $line['verified_at'],
                'parameters' => array_map(fn (array $parameter) => array_intersect_key($parameter, array_flip(['id', 'name', 'unit', 'range', 'value', 'flag'])), $line['parameters']),
            ])->values(),
        ]]);
    }
}
