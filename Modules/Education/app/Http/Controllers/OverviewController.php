<?php

namespace Modules\Education\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Http\Controllers\Concerns\FindsEducation;
use Modules\Education\Http\EducationPresenter;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\PromotionBatch;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;
use Modules\Education\Services\Access;
use Modules\Education\Services\Education;

/**
 * The education overview of the unit in the address (education.view): a
 * session (the one asked for, else the latest open one), its sections with
 * how full they are, and what waits (applications, promotions). A teacher
 * sees their own sections only (rule education.teacher_scope).
 */
class OverviewController extends Controller
{
    use FindsEducation;

    /** A section this full (in percent) shows as nearly full. */
    private const NEARLY_FULL = 90;

    public function __construct(private Education $education, private Access $access, private EducationPresenter $presenter) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('education.view', $unit);
        $asked = $request->validate(['session_id' => ['nullable', 'string', 'size:26']])['session_id'] ?? null;
        $units = $this->unitIds($unit);
        $visible = $this->access->sections($company, $unit, $request->user());

        $sessions = $this->education->query(Session::class, $company)->orderByDesc('starts_on')->get();
        $session = $asked !== null ? $sessions->firstWhere('id', $asked) : ($sessions->firstWhere('status', 'open') ?? $sessions->first());

        $sections = $session === null ? collect() : $this->education->query(Section::class, $company)
            ->where('session_id', $session->getKey())->whereIn('unit_id', $units)
            ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))
            ->orderBy('level_id')->orderBy('name')->get();
        $taken = $this->education->query(Enrollment::class, $company)->whereIn('section_id', $sections->pluck('id'))->where('status', 'active')
            ->get(['section_id'])->countBy('section_id');

        $students = $this->education->query(Student::class, $company)->whereIn('unit_id', $units)->where('status', 'active');
        $this->access->restrict($students, $company, $visible);
        $unplaced = $session === null ? 0 : $this->education->query(Enrollment::class, $company)->where('session_id', $session->getKey())
            ->whereIn('unit_id', $units)->where('status', 'active')->whereNull('section_id')->count();

        $presented = $sections->map(fn (Section $section) => [...$this->presenter->structure('sections', $section), 'taken' => (int) ($taken[$section->getKey()] ?? 0)])->values();

        return response()->json(['data' => [
            'session_id' => $session?->getKey(),
            'sections' => $presented,
            'stats' => [
                'students' => $students->count(),
                'unplaced' => $unplaced,
                'sections' => $presented->count(),
                'nearly_full' => $presented->filter(fn (array $section) => $section['capacity'] > 0 && $section['taken'] * 100 >= $section['capacity'] * self::NEARLY_FULL)->count(),
                'applications' => Gate::allows('education.admit', $unit)
                    ? $this->education->query(Admission::class, $company)->whereIn('unit_id', $units)->whereIn('status', ['applied', 'test', 'offered'])->count() : null,
                'promotions_waiting' => Gate::allows('education.approve_promotion', $unit) || Gate::allows('education.promote', $unit)
                    ? $this->education->query(PromotionBatch::class, $company)->whereIn('unit_id', $units)->where('status', 'pending_approval')->count() : null,
            ],
        ]]);
    }
}
