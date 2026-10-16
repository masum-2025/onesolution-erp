<?php

namespace Modules\TeacherFeedback\Http\Controllers;

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
use Modules\TeacherFeedback\Exceptions\FeedbackException;
use Modules\TeacherFeedback\Services\FeedbackOffice;
use Modules\TeacherFeedback\Services\Ratings;

/**
 * Teacher feedback in the client's portal (B2B2C): the student's own
 * (their "self" link) or, with record, a child a guardian is linked to.
 * Only open rounds of the student's section, only their teachers, once.
 */
class PortalFeedbackController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private FeedbackOffice $office,
        private AcademicDirectory $academic,
        private Ratings $ratings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['record' => ['nullable', 'string', 'size:26']]);
        [$company, $student, $enrollment, $relation] = $this->me($data['record'] ?? null);

        return response()->json(['data' => [
            'student' => ['id' => $student['id'], 'name' => $student['name']],
            'rounds' => $this->ratings->open($company, $enrollment, $student['id'], $relation, $request->user()),
            'allow_comments' => $this->commentsAllowed($enrollment),
        ]]);
    }

    public function rate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'record' => ['nullable', 'string', 'size:26'],
            'round_id' => ['required', 'string', 'size:26'],
            'teacher_id' => ['required', 'string', 'size:26'],
            'subject_id' => ['required', 'string', 'size:26'],
            'answers' => ['required', 'array', 'min:1', 'max:12'],
            'answers.*' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:'.Ratings::MAX_COMMENT],
            'op_id' => ['nullable', 'string', 'max:64'],
        ]);
        [$company, $student, $enrollment, $relation] = $this->me($data['record'] ?? null);
        // A resent tap (same op) after the rating was kept: answer as done, write nothing.
        if (($data['op_id'] ?? null) !== null && $this->ratings->given($company, $data['round_id'], $student['id'], $request->user(), $data['teacher_id'], $data['subject_id'])) {
            return response()->json(['data' => ['done' => true]]);
        }
        $this->ratings->rate($company, $enrollment, $student['id'], $relation, $request->user(), $data['round_id'], $data['teacher_id'], $data['subject_id'],
            $data['answers'], $data['comment'] ?? null, $this->commentsAllowed($enrollment));

        return response()->json(['data' => ['done' => true], 'message' => __('teacher_feedback::feedback.thanks')], 201);
    }

    /**
     * The student behind the portal login (or the linked child), where they study now.
     *
     * @return array{0: Organization, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}
     */
    private function me(?string $record): array
    {
        if (! $this->portal->isPortal()) {
            throw FeedbackException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)
            ->when($record === null, fn ($query) => $query->where('relation', 'self'), fn ($query) => $query->whereKey($record))
            ->first(['subject_id', 'relation']);
        $company = $this->office->companyOf($this->context->organization());
        $student = $link === null ? null : $this->academic->student($company, $link->subject_id);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $student['id']);
        if ($student === null || ($enrollment['section_id'] ?? null) === null) {
            throw FeedbackException::notFound('student');
        }

        return [$company, $student, $enrollment, (string) $link->relation];
    }

    /** @param  array<string, mixed>  $enrollment */
    private function commentsAllowed(array $enrollment): bool
    {
        $unit = Organization::query()->find($enrollment['unit_id']);

        return $unit !== null && (bool) $this->rules->get('teacher_feedback.allow_comments', $this->contexts->forOrganization($unit));
    }
}
