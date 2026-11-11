<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Diagnostics\Contracts\TestOrders;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\Requests\ConsultationRequest;
use Modules\Outpatient\Models\Consultation;
use Modules\Outpatient\Models\PrescriptionItem;
use Modules\Outpatient\Models\Serial;
use Modules\Outpatient\Models\Template;
use Modules\Outpatient\Services\Consultations;
use Modules\Outpatient\Services\ConsultationView;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Outpatient\Services\Serials;
use Modules\Outpatient\Services\SerialView;
use Modules\Outpatient\Services\Templates;
use Modules\Pharmacy\Contracts\MedicineCatalogue;

/**
 * What the doctor writes for a serial, read and written with
 * outpatient.consult (the desk without it never sees a diagnosis): the
 * consultation with the patient's earlier visits and what the screen needs,
 * saving a draft, finishing, amending, sending tests to the lab, the printed
 * prescription, and medicines from the pharmacy's list.
 */
class ConsultationController extends Controller
{
    use FindsOutpatient;

    public function __construct(
        private OutpatientStore $store,
        private Serials $serials,
        private SerialView $serialView,
        private Consultations $consultations,
        private ConsultationView $view,
        private Templates $templates,
    ) {}

    public function show(Request $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);
        $current = $this->consultations->current($company, $found);
        $lab = $this->consultations->labOn($company);
        $chamberUnit = Organization::query()->find($found->unit_id) ?? $unit;

        return response()->json(['data' => [
            'serial' => $this->serialView->rows($company, collect([$found]))[0],
            'consultation' => $current === null ? null : $this->view->full($company, $current),
            'revisions' => $this->revisions($company, $found),
            'history' => $this->view->history($company, $found->patient_id, (string) $found->getKey()),
            'doctor' => $this->view->doctor($company, $found->doctor_id),
            'options' => [
                'pharmacy' => $this->consultations->pharmacyOn($company),
                'lab' => $lab,
                'tests' => $lab ? app(TestOrders::class)->catalogue($company) : [],
                'timings' => PrescriptionItem::TIMINGS,
                'language' => (string) $this->store->rule('prescription_language', $chamberUnit),
                'templates' => $this->templates->visible($company, $request->user())->map(fn (Template $template) => [
                    'id' => $template->getKey(), 'name' => $template->name, 'shared' => $template->doctor_id === null, 'body' => $template->body,
                ])->values(),
            ],
        ]]);
    }

    public function save(ConsultationRequest $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);
        $data = $request->validated();
        $saved = $this->consultations->save($company, $unit, $found, $data, isset($data['base_version']) ? (int) $data['base_version'] : null, $request->user());

        return response()->json(['data' => $this->view->full($company, $saved)], $saved->wasRecentlyCreated ? 201 : 200);
    }

    public function finish(ConsultationRequest $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);
        $done = $this->consultations->finish($company, $unit, $found, (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->view->full($company, $done)]);
    }

    public function amend(ConsultationRequest $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);
        $next = $this->consultations->amend($company, $unit, $found, $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->view->full($company, $next)], 201);
    }

    public function sendTests(Request $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);

        return response()->json(['data' => $this->view->full($company, $this->consultations->sendTests($company, $unit, $found, $request->user()))]);
    }

    /** The prescription in force, for printing in the rule's language(s). */
    public function prescription(Request $request, string $organization, string $serial): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        $found = $this->serials->visible($company, $unit, $request->user(), $serial);
        $consultation = $this->consultations->inForce($company, $found) ?? throw OutpatientException::because('not_finished', 404);
        $chamberUnit = Organization::query()->find($found->unit_id) ?? $unit;

        return response()->json(['data' => [
            ...$this->view->full($company, $consultation),
            'serial' => $this->serialView->rows($company, collect([$found]))[0],
            'doctor' => $this->view->doctor($company, $found->doctor_id),
            'language' => (string) $this->store->rule('prescription_language', $chamberUnit),
        ]]);
    }

    /** Medicines from the pharmacy's list (when Pharmacy is on). */
    public function medicines(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        if (! $this->consultations->pharmacyOn($company)) {
            throw OutpatientException::because('pharmacy_off', 403);
        }
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(['data' => app(MedicineCatalogue::class)->search($company, $term, 15)]);
    }

    /** @return list<array<string, mixed>> */
    private function revisions(Organization $company, Serial $serial): array
    {
        return $this->store->query(Consultation::class, $company)->where('serial_id', $serial->getKey())->orderBy('revision')
            ->get(['id', 'revision', 'status', 'finished_at', 'superseded_at', 'amend_reason'])
            ->map(fn ($c) => ['revision' => $c->revision, 'status' => $c->status, 'finished_at' => $c->finished_at?->toIso8601String(), 'superseded' => $c->superseded_at !== null, 'amend_reason' => $c->amend_reason])
            ->values()->all();
    }
}
