<?php

namespace Modules\EducationExams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Directory\ResultDirectory;
use Modules\EducationExams\Exceptions\ExamException;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\ReportCards;
use Modules\EducationExams\Services\ResultHolds;

/**
 * A family's results in the client's portal (B2B2C): the published results
 * of a student the member is linked to (the student, a parent), chosen by
 * the portal record (?record=), and the report card of one of them. Drafts,
 * other students and staff details (who entered marks, corrections) stay
 * out; results held for fees owed show nothing but the office to contact.
 * Another student's result is the same 404.
 */
class PortalResultController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private ExamOffice $office,
        private ResultHolds $holds,
        private ReportCards $cards,
    ) {}

    /** The student's published results and cumulative GPA (or that they are held). */
    public function show(Request $request): JsonResponse
    {
        [$company, $unit, $student] = $this->me($request);
        $held = $this->holds->held($company, $unit, [$student['id']]) !== [];
        $transcript = $held ? ['results' => [], 'cgpa_centi' => null, 'credits_centi' => 0] : app(ResultDirectory::class)->transcript($company, $student['id']);
        $showRank = (bool) app(RuleResolver::class)->get('education_exams.show_rank', app(RuleContextFactory::class)->forOrganization($unit));
        if (! $showRank) {
            $transcript['results'] = array_map(fn (array $row) => [...$row, 'rank' => null, 'section_rank' => null], $transcript['results']);
        }

        // Names go with the results: a family has no exam setup to look them up.
        $academic = app(AcademicDirectory::class);
        $results = collect($transcript['results']);
        $subjectIds = $results->flatMap(fn (array $row) => array_column($row['subjects'], 'subject_id'))->unique()->values()->all();

        return response()->json(['data' => [
            'student' => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])),
            'institution' => $company->displayName(),
            'held' => $held,
            'show_rank' => $showRank,
            ...$transcript,
            'names' => [
                'subjects' => collect($academic->subjects($company, $subjectIds))->map(fn (array $subject) => array_intersect_key($subject, array_flip(['code', 'name'])))->all(),
                'sessions' => $results->pluck('session_id')->unique()->filter()->mapWithKeys(fn (string $id) => [$id => $academic->session($company, $id)['name'] ?? []])->all(),
                'levels' => $results->pluck('level_id')->unique()->filter()->mapWithKeys(fn (string $id) => [$id => $academic->level($company, $id)['name'] ?? []])->all(),
            ],
        ]]);
    }

    /** One published result's report card, to print. */
    public function reportCard(Request $request, string $result): JsonResponse
    {
        [$company, $unit, $student] = $this->me($request);
        $row = $this->office->query(Result::class, $company)->whereKey($result)->where('student_id', $student['id'])->where('status', 'published')->first()
            ?? throw ExamException::notFound('result');
        if ($this->holds->held($company, $unit, [$student['id']]) !== []) {
            throw ExamException::resultHeld();
        }
        $source = $row->source_type === 'exam'
            ? $this->office->query(Exam::class, $company)->find($row->source_id)
            : $this->office->query(Combination::class, $company)->find($row->source_id);

        return response()->json(['data' => $this->cards->build($company, $unit, $source ?? throw ExamException::notFound('result'), collect([$row]))]);
    }

    /**
     * The institution, the member's unit and the linked student.
     *
     * @return array{0: Organization, 1: Organization, 2: array<string, mixed>}
     */
    private function me(Request $request): array
    {
        $record = $request->validate(['record' => ['required', 'string', 'size:26']])['record'];
        if (! $this->portal->isPortal()) {
            throw ExamException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->whereKey($record)->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)->first();
        $unit = $this->context->organization();
        $company = $this->office->companyOf($unit);
        $student = $link === null ? null : app(AcademicDirectory::class)->student($company, $link->subject_id);

        return [$company, $unit, $student ?? throw ExamException::notFound('student')];
    }
}
