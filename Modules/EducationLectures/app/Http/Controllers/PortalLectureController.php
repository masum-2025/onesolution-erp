<?php

namespace Modules\EducationLectures\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationLectures\Exceptions\LectureException;
use Modules\EducationLectures\Http\LecturePresenter;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Models\LectureFile;
use Modules\EducationLectures\Services\Classroom;

/**
 * Lectures in the client's portal (B2B2C): the student's own (their
 * "self" link) or, with ?record=, a child a guardian is linked to; only
 * the published lectures of the section they study in now. Anything else
 * is the same 404.
 */
class PortalLectureController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Classroom $classroom,
        private AcademicDirectory $academic,
        private LecturePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'record' => ['nullable', 'string', 'size:26'], 'subject_id' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (! $this->portal->isPortal()) {
            throw LectureException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)
            ->when(($data['record'] ?? null) === null, fn ($query) => $query->where('relation', 'self'), fn ($query) => $query->whereKey($data['record']))
            ->first(['subject_id']);
        $company = $this->classroom->companyOf($this->context->organization());
        $student = $link === null ? null : $this->academic->student($company, $link->subject_id);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $student['id']);
        if ($student === null || ($enrollment['section_id'] ?? null) === null) {
            throw LectureException::notFound('student');
        }

        $base = $this->classroom->query(Lecture::class, $company)->where('section_id', $enrollment['section_id'])->where('status', 'published');
        $page = (clone $base)->when($data['subject_id'] ?? null, fn ($query, $id) => $query->where('subject_id', $id))
            ->orderByDesc('lesson_on')->orderByDesc('published_at')->paginate(PerPage::from($request, 20));
        $lectures = collect($page->items());
        $files = $this->classroom->query(LectureFile::class, $company)->whereIn('lecture_id', $lectures->pluck('id'))->orderBy('created_at')->get()->groupBy('lecture_id');
        $subjectIds = (clone $base)->distinct()->pluck('subject_id')->all();
        $subjects = $this->academic->subjects($company, $subjectIds);

        return response()->json([
            'data' => $lectures->map(fn (Lecture $lecture) => $this->presenter->lecture($lecture, $files[$lecture->getKey()] ?? collect()))->values(),
            'meta' => [
                'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'student' => ['id' => $student['id'], 'name' => $student['name']],
                // Subjects with lectures, to filter by.
                'subjects' => collect($subjects)->map(fn (array $subject, string $id) => ['id' => $id, 'code' => $subject['code'], 'name' => $subject['name']])->values(),
                'teachers' => (object) $this->academic->teacherNames($company, $lectures->pluck('teacher_id')->filter()->unique()->values()->all()),
            ],
        ]);
    }
}
