<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Http\Controllers\Concerns\FindsPlace;
use Modules\Recruitment\Http\RecruitmentPresenter;
use Modules\Recruitment\Http\Requests\LetterRequest;
use Modules\Recruitment\Http\Requests\OfferRequest;
use Modules\Recruitment\Http\Requests\OfferStepRequest;
use Modules\Recruitment\Models\Offer;
use Modules\Recruitment\Services\LetterTemplates;
use Modules\Recruitment\Services\Offers;
use Modules\Recruitment\Services\Place;

/**
 * Offers of applications at a unit (and below it): one with its letter
 * (for reading and printing), writing a draft and sending or withdrawing
 * it (recruitment.manage where the job is), approving it or sending it
 * back (someone else with recruitment.hire there); and the company's offer
 * letter (seen with recruitment.view, changed with recruitment.manage).
 */
class OfferController extends Controller
{
    use FindsPlace;

    private const SEES = ['recruitment.view', 'recruitment.manage', 'recruitment.hire'];

    public function __construct(private Place $place, private Offers $offers, private RecruitmentPresenter $presenter) {}

    public function show(Request $request, string $organization, string $offer): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        [$found, $where] = $this->offerIn($unit, $company, $offer);
        abort_unless(Gate::any(self::SEES, $where), 403);

        return response()->json(['data' => [
            ...$this->presenter->offer($company, $found, $request->user()),
            'letter' => $found->letter ?? $this->offers->letter($company, $found),
            'company' => $company->displayName(app()->getLocale()),
        ]]);
    }

    public function store(OfferRequest $request, string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $offer = $this->offers->save($company, $found, null, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->offer($company, $offer, $request->user())], 201);
    }

    public function update(OfferRequest $request, string $organization, string $offer): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        [$found, $where] = $this->offerIn($unit, $company, $offer);
        Gate::authorize('recruitment.manage', $where);
        $application = $this->applicationIn($unit, $company, $found->application_id);
        $saved = $this->offers->save($company, $application, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->offer($company, $saved, $request->user())]);
    }

    /** send | approve | return | withdraw */
    public function step(OfferStepRequest $request, string $organization, string $offer, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['send', 'approve', 'return', 'withdraw'], true), 404);
        [$found, $where] = $this->offerIn($unit, $company, $offer);
        Gate::authorize(in_array($step, ['approve', 'return'], true) ? 'recruitment.hire' : 'recruitment.manage', $where);
        $done = $this->offers->step($company, $found, $step, (int) $request->validated('base_version'), $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->offer($company, $done, $request->user())]);
    }

    /** The company's offer letter, the placeholders it may use, and a sample filled in. */
    public function letter(string $organization, LetterTemplates $letters): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);

        return response()->json(['data' => $this->letterData($company, $letters)]);
    }

    public function saveLetter(LetterRequest $request, string $organization, LetterTemplates $letters): JsonResponse
    {
        [, $company] = $this->place($organization);
        Gate::authorize('recruitment.manage', $company);
        $letters->save($company, $request->validated('body'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->letterData($company, $letters)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function letterData(Organization $company, LetterTemplates $letters): array
    {
        $locale = app()->getLocale();

        return [
            ...$letters->of($company),
            'placeholders' => LetterTemplates::PLACEHOLDERS,
            'can_manage' => Gate::allows('recruitment.manage', $company),
            'sample' => $letters->render($company, $locale, [
                'candidate.name' => __('recruitment::recruitment.sample.name', [], $locale), 'job.title' => __('recruitment::recruitment.sample.job', [], $locale),
                'company.name' => $company->displayName($locale), 'unit.name' => $company->displayName($locale),
                'salary' => LetterTemplates::money(3000000, 'BDT', $locale),
                'start_date' => LetterTemplates::date($this->place->today($company)->addMonth(), $locale),
                'respond_by' => LetterTemplates::date($this->place->today($company)->addWeek(), $locale),
                'today' => LetterTemplates::date($this->place->today($company), $locale),
            ]),
        ];
    }

    /**
     * An offer of an application at the unit or below it, and the unit where the job is.
     *
     * @return array{0: Offer, 1: Organization}
     */
    private function offerIn(Organization $unit, Organization $company, string $id): array
    {
        $offer = $this->place->query(Offer::class, $company)->whereKey($id)->first() ?? throw RecruitmentException::offerNotFound();
        try {
            $application = $this->applicationIn($unit, $company, $offer->application_id);
        } catch (RecruitmentException) {
            throw RecruitmentException::offerNotFound();
        }

        return [$offer, $this->unitOf($application->unit_id)];
    }
}
