<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Services\Documents;
use Modules\Education\Services\Education;

/**
 * The public check behind a document's QR code: no sign-in, throttled per
 * address. It answers whether the document is valid, revoked or expired,
 * with the institution, the kind, number and dates, and of the student only
 * what the rule education.verify_shows allows (name and class at most;
 * never private details or the photo). A wrong code, an unknown institution
 * and Education switched off all get the same answer, so codes cannot be
 * guessed or institutions probed.
 */
class VerifyController extends Controller
{
    public function __invoke(string $organization, string $code, Documents $documents, Education $education, RuleResolver $rules, RuleContextFactory $contexts): JsonResponse
    {
        $company = preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $organization) === 1 ? Organization::query()->find($organization) : null;
        if ($company === null || $company->type !== OrganizationType::Company || ! app(ModuleResolver::class)->isEnabled('education', $company)) {
            throw EducationException::verifyNotFound();
        }
        $document = $documents->byCode($company, $code) ?? throw EducationException::verifyNotFound();

        $shows = (array) $rules->get('education.verify_shows', $contexts->forOrganization($company));
        $summary = $document->snapshot['summary'] ?? [];
        $brand = app(BrandResolver::class)->for($company->partner, $company);

        return response()->json(['data' => [
            'status' => $document->statusOn($education->today($company)),
            'institution' => ['name' => $company->displayName(), 'logo_url' => $brand['logo_url'] ?? null, 'color' => $brand['primary_color'] ?? null],
            'document' => [
                'kind' => $document->kind,
                'title' => $document->textIn('title'),
                'number' => $document->number,
                'issued_on' => in_array('issued_on', $shows, true) ? $document->issued_on->toDateString() : null,
                'valid_until' => in_array('valid_until', $shows, true) ? $document->valid_until?->toDateString() : null,
                'revoked_on' => $document->revoked_at?->toDateString(),
            ],
            'student' => [
                'name' => in_array('student_name', $shows, true) ? ($summary['student_name'] ?? null) : null,
                'level' => in_array('level', $shows, true) ? ($summary['level'] ?? null) : null,
            ],
        ]]);
    }
}
