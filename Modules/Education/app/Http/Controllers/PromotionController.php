<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Models\PromotionBatch;
use Modules\Education\Models\PromotionLine;
use Modules\Education\Models\Student;
use Modules\Education\Services\Education;
use Modules\Education\Services\Promotions;

/**
 * Promotion lists at the unit in the address and the campuses below:
 * made, changed, handed in, cancelled and undone with education.promote;
 * approved or sent back with education.approve_promotion (never by the
 * person who made or handed in the list).
 */
class PromotionController extends Controller
{
    use FindsEducation;

    public function __construct(private Education $education, private Promotions $promotions) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $this->authorizeAny($unit);
        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', PromotionBatch::STATUSES)]])['status'] ?? null;
        $batches = $this->education->query(PromotionBatch::class, $company)->whereIn('unit_id', $this->unitIds($unit))
            ->when($status, fn ($query) => $query->where('status', $status))->orderByDesc('created_at')->limit(200)->get();
        $counts = $this->education->query(PromotionLine::class, $company)->whereIn('batch_id', $batches->pluck('id'))->get(['batch_id', 'decision'])
            ->groupBy('batch_id')->map(fn ($lines) => $lines->countBy('decision')->all());

        return response()->json(['data' => $batches->map(fn (PromotionBatch $batch) => [...$this->batch($batch), 'decisions' => (object) ($counts[$batch->getKey()] ?? [])])->values()]);
    }

    public function show(string $organization, string $batch): JsonResponse
    {
        [$unit, $company, $found] = $this->found($organization, $batch);
        $this->authorizeAny($unit);

        return response()->json(['data' => $this->detail($company, $found)]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw EducationException::notCompanyUnit();
        }
        Gate::authorize('education.promote', $unit);
        $data = $request->validate([
            'from_session_id' => ['required', 'string', 'size:26'], 'to_session_id' => ['required', 'string', 'size:26'],
            'level_id' => ['required', 'string', 'size:26'], 'section_id' => ['nullable', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $made = $this->promotions->create($company, $unit->getKey(), $this->unitIds($unit), $data, $request->user());

        return response()->json(['data' => $this->detail($company, $made)], 201);
    }

    public function decide(Request $request, string $organization, string $batch): JsonResponse
    {
        [$unit, $company, $found] = $this->found($organization, $batch);
        Gate::authorize('education.promote', $unit);
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'],
            'section_id' => ['sometimes', 'string', 'size:26'],
            'lines' => ['required_without:section_id', 'array', 'max:2000'],
            'lines.*' => ['array:line_id,decision,to_section_id,reason'],
            'lines.*.line_id' => ['required', 'string', 'size:26'],
            'lines.*.decision' => ['required', 'in:'.implode(',', PromotionLine::DECISIONS)],
            'lines.*.to_section_id' => ['nullable', 'string', 'size:26'],
            'lines.*.reason' => ['nullable', 'string', 'max:300'],
        ]);
        $changed = isset($data['section_id'])
            ? $this->promotions->placeAll($company, $found, (int) $data['base_version'], $data['section_id'], $request->user())
            : $this->promotions->decide($company, $found, (int) $data['base_version'], $data['lines'], $request->user());

        return response()->json(['data' => $this->detail($company, $changed)]);
    }

    /** submit | approve | reject | cancel | undo */
    public function step(Request $request, string $organization, string $batch, string $step): JsonResponse
    {
        [$unit, $company, $found] = $this->found($organization, $batch);
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'note' => [$step === 'reject' ? 'required' : 'nullable', 'string', 'min:3', 'max:500']]);
        $version = (int) $data['base_version'];
        $user = $request->user();

        $changed = match ($step) {
            'submit' => $this->with('education.promote', $unit, fn () => $this->promotions->submit($company, $found, $version, $user)),
            'cancel' => $this->with('education.promote', $unit, fn () => $this->promotions->cancel($company, $found, $version, $user)),
            'undo' => $this->with('education.promote', $unit, fn () => $this->promotions->undo($company, $found, $version, $user)),
            'approve' => $this->with('education.approve_promotion', $unit, fn () => $this->promotions->approve($company, $found, $version, $user)),
            'reject' => $this->with('education.approve_promotion', $unit, fn () => $this->promotions->reject($company, $found, $version, $data['note'], $user)),
            default => throw EducationException::notFound('record'),
        };

        return response()->json(['data' => $this->detail($company, $changed), 'message' => __("education::education.messages.promotion_{$changed->status}")]);
    }

    private function with(string $permission, Organization $unit, callable $action): PromotionBatch
    {
        Gate::authorize($permission, $unit);

        return $action();
    }

    private function authorizeAny(Organization $unit): void
    {
        if (! Gate::allows('education.promote', $unit) && ! Gate::allows('education.approve_promotion', $unit)) {
            Gate::authorize('education.promote', $unit);
        }
    }

    /** @return array{0: Organization, 1: Organization, 2: PromotionBatch} */
    private function found(string $organization, string $batch): array
    {
        [$unit, $company] = $this->workplace($organization);

        return [$unit, $company, $this->recordIn(PromotionBatch::class, $unit, $company, $batch, 'promotion')];
    }

    /** @return array<string, mixed> */
    private function batch(PromotionBatch $batch): array
    {
        return [
            'id' => $batch->getKey(),
            'unit_id' => $batch->unit_id,
            'number' => $batch->number,
            'from_session_id' => $batch->from_session_id,
            'to_session_id' => $batch->to_session_id,
            'level_id' => $batch->level_id,
            'section_id' => $batch->section_id,
            'status' => $batch->status,
            'note' => $batch->note,
            'created_by' => $batch->created_by,
            'submitted_by' => $batch->submitted_by,
            'approved_by' => $batch->approved_by,
            'applied_at' => $batch->applied_at?->toIso8601String(),
            'undo_until' => $batch->undo_until?->toDateString(),
            'version' => $batch->version,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Organization $company, PromotionBatch $batch): array
    {
        $lines = $this->promotions->lines($company, $batch);
        $students = $this->education->query(Student::class, $company)->whereKey($lines->pluck('student_id'))->get()->keyBy('id');

        return [
            ...$this->batch($batch),
            'lines' => $lines->map(fn (PromotionLine $line) => [
                'id' => $line->getKey(),
                'student_id' => $line->student_id,
                'code' => $students[$line->student_id]?->code,
                'name' => $students[$line->student_id]?->name,
                'decision' => $line->decision,
                'to_level_id' => $line->to_level_id,
                'to_section_id' => $line->to_section_id,
                'reason' => $line->reason,
                'repeats' => $line->repeats,
                'result_enrollment_id' => $line->result_enrollment_id,
            ])->sortBy('name')->values(),
        ];
    }
}
