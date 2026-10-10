<?php

namespace Modules\EducationFees\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationFees\Exceptions\FeeException;
use Modules\EducationFees\Http\Controllers\Concerns\FindsFeeUnit;
use Modules\EducationFees\Http\FeePresenter;
use Modules\EducationFees\Models\Concession;
use Modules\EducationFees\Models\FeeHead;
use Modules\EducationFees\Services\Concessions;
use Modules\EducationFees\Services\FeeOffice;

/**
 * Students' discounts and scholarships at the unit and below: listed with
 * education_fees.view, given and ended with .concede, approved or rejected
 * with .approve (never by the one who asked).
 */
class ConcessionController extends Controller
{
    use FindsFeeUnit;

    public function __construct(private FeeOffice $office, private Concessions $concessions, private FeePresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.view', $unit);
        $filters = $request->validate(['student_id' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', Rule::in(Concession::STATUSES)]]);
        $rows = $this->office->query(Concession::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($filters['student_id'] ?? null, fn ($query, $id) => $query->where('student_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(500)->get();
        $students = app(AcademicDirectory::class)->students($company, $rows->pluck('student_id')->unique()->values()->all());

        return response()->json(['data' => $rows->map(fn (Concession $row) => $this->presenter->concession($row, $students[$row->student_id] ?? null))->values()]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.concede', $unit);
        $heads = $this->office->query(FeeHead::class, $company)->pluck('id')->all();
        $data = $request->validate([
            'student_id' => ['required', 'string', 'size:26'],
            'head_id' => ['nullable', Rule::in($heads)],
            'mode' => ['required', 'in:percent,fixed'],
            'percent_bp' => ['required_if:mode,percent', 'prohibited_unless:mode,percent', 'nullable', 'integer', 'min:1', 'max:10000'],
            'amount_minor' => ['required_if:mode,fixed', 'prohibited_unless:mode,fixed', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $student = app(AcademicDirectory::class)->student($company, $data['student_id']);
        if ($student === null || ! in_array($student['unit_id'], $this->unitIds($unit), true)) {
            throw FeeException::notFound('student');
        }
        $concession = $this->concessions->give($company, [...$data, 'unit_id' => $student['unit_id']], $request->user());

        return response()->json(['data' => $this->presenter->concession($concession, $student)], 201);
    }

    public function approve(Request $request, string $organization, string $concession): JsonResponse
    {
        return $this->decide($request, $organization, $concession, true);
    }

    public function reject(Request $request, string $organization, string $concession): JsonResponse
    {
        return $this->decide($request, $organization, $concession, false);
    }

    public function end(Request $request, string $organization, string $concession): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.concede', $unit);
        $found = $this->recordIn(Concession::class, $unit, $company, $concession, 'concession');
        $data = $request->validate(['ends_on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->concessions->end($company, $found, $data['ends_on'], $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->presenter->concession($saved, app(AcademicDirectory::class)->student($company, $saved->student_id))]);
    }

    private function decide(Request $request, string $organization, string $concession, bool $approve): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education_fees.approve', $unit);
        $found = $this->recordIn(Concession::class, $unit, $company, $concession, 'concession');
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:300'], 'base_version' => ['required', 'integer']]);
        $saved = $this->concessions->decide($company, $found, $approve, $data['note'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->presenter->concession($saved, app(AcademicDirectory::class)->student($company, $saved->student_id))]);
    }
}
