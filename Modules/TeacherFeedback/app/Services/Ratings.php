<?php

namespace Modules\TeacherFeedback\Services;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Modules\Education\Directory\AcademicDirectory;
use Modules\TeacherFeedback\Exceptions\FeedbackException;
use Modules\TeacherFeedback\Models\Answer;
use Modules\TeacherFeedback\Models\Comment;
use Modules\TeacherFeedback\Models\Question;
use Modules\TeacherFeedback\Models\Rater;
use Modules\TeacherFeedback\Models\Round;

/**
 * Rating in the portal. A student (their "self" link) or a guardian
 * (linked to the child), as the round says, rates each teacher who teaches
 * the student a subject in their section now, once per round and subject.
 *
 * Anonymity: the only trace of who rated is a keyed hash of the round, the
 * student and the login (tfb_raters), kept to stop a second rating; the
 * stars and the comment are written without it, without a time and with
 * ids that carry no time or order (HasUntimedUlids).
 */
class Ratings
{
    public const MAX_COMMENT = 1000;

    public function __construct(private FeedbackOffice $office, private AcademicDirectory $academic) {}

    /**
     * Rounds open today for the student's section that this person may answer
     * (as the student or a guardian), each with the student's teachers and
     * whether they were rated already.
     *
     * @param  array<string, mixed>  $enrollment
     * @return list<array<string, mixed>>
     */
    public function open(Organization $company, array $enrollment, string $studentId, string $relation, User $user): array
    {
        $today = $this->office->today($company)->toDateString();
        $section = $this->academic->section($company, $enrollment['section_id']);
        $rounds = $this->office->query(Round::class, $company)->where('status', 'open')->where('session_id', $enrollment['session_id'])
            ->where('opens_on', '<=', $today)->where('closes_on', '>=', $today)->orderBy('closes_on')->get()
            ->filter(fn (Round $round) => in_array($section['unit_id'] ?? '', $this->office->subtreeIds(Organization::query()->findOrFail($round->unit_id)), true))
            ->filter(fn (Round $round) => $this->mayAnswer($round, $relation))->values();
        if ($rounds->isEmpty()) {
            return [];
        }

        $teachers = collect($this->academic->subjectTeachers($company, [$enrollment['section_id']]));
        $names = $this->academic->teacherNames($company, $teachers->pluck('teacher_id')->unique()->values()->all());
        $subjects = $this->academic->subjects($company, $teachers->pluck('subject_id')->unique()->values()->all());

        return $rounds->map(function (Round $round) use ($company, $teachers, $names, $subjects, $studentId, $user) {
            $done = $this->office->query(Rater::class, $company)->where('round_id', $round->getKey())->where('rater_hash', $this->hash($round, $studentId, $user))
                ->get(['teacher_id', 'subject_id'])->map(fn (Rater $rater) => "{$rater->teacher_id}|{$rater->subject_id}")->all();

            return [
                'id' => $round->getKey(), 'name' => $round->name, 'closes_on' => $round->closes_on->toDateString(), 'version' => $round->version,
                'questions' => $this->questions($company, $round)->map(fn (Question $question) => ['id' => $question->getKey(), 'text' => $question->texts('text')])->values()->all(),
                'teachers' => $teachers->filter(fn (array $row) => isset($subjects[$row['subject_id']]))->map(fn (array $row) => [
                    'teacher_id' => $row['teacher_id'], 'teacher' => $names[$row['teacher_id']] ?? null,
                    'subject_id' => $row['subject_id'], 'subject' => ['code' => $subjects[$row['subject_id']]['code'], 'name' => $subjects[$row['subject_id']]['name']],
                    'done' => in_array("{$row['teacher_id']}|{$row['subject_id']}", $done, true),
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * One rating: every question's stars (1-5) and maybe a comment.
     *
     * @param  array<string, mixed>  $enrollment
     * @param  array<string, int>  $answers  question id => stars
     */
    public function rate(Organization $company, array $enrollment, string $studentId, string $relation, User $user, string $roundId, string $teacherId, string $subjectId, array $answers, ?string $comment, bool $commentsAllowed): void
    {
        $round = $this->office->query(Round::class, $company)->whereKey($roundId)->where('session_id', $enrollment['session_id'])->first()
            ?? throw FeedbackException::notFound('round');
        $section = $this->academic->section($company, $enrollment['section_id']);
        if (! in_array($section['unit_id'] ?? '', $this->office->subtreeIds(Organization::query()->findOrFail($round->unit_id)), true)) {
            throw FeedbackException::notFound('round');
        }
        $today = $this->office->today($company)->toDateString();
        if ($round->status !== 'open' || $today < $round->opens_on->toDateString() || $today > $round->closes_on->toDateString()) {
            throw FeedbackException::roundClosed($round->opens_on->toDateString(), $round->closes_on->toDateString());
        }
        if (! $this->mayAnswer($round, $relation)) {
            throw FeedbackException::notYourTurn();
        }
        $teaches = collect($this->academic->subjectTeachers($company, [$enrollment['section_id']]))
            ->contains(fn (array $row) => $row['teacher_id'] === $teacherId && $row['subject_id'] === $subjectId);
        if (! $teaches) {
            throw FeedbackException::notTheirTeacher();
        }
        $questions = $this->questions($company, $round)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $given = collect(array_keys($answers))->map(fn ($id) => (string) $id)->sort()->values()->all();
        if ($questions !== $given) {
            throw FeedbackException::unansweredQuestions();
        }

        $base = ['organization_id' => $company->getKey(), 'round_id' => $round->getKey(), 'section_id' => $enrollment['section_id'], 'teacher_id' => $teacherId, 'subject_id' => $subjectId];
        try {
            $this->office->transaction($company, function () use ($company, $base, $answers, $comment, $commentsAllowed, $round, $studentId, $user) {
                $this->office->query(Rater::class, $company)->create([...$base, 'rater_hash' => $this->hash($round, $studentId, $user)]);
                // In a random order, so even the order rows are written in tells nothing.
                foreach (collect(array_keys($answers))->shuffle() as $questionId) {
                    $this->office->query(Answer::class, $company)->create([...$base, 'question_id' => (string) $questionId, 'stars' => (int) $answers[$questionId]]);
                }
                $text = trim((string) $comment);
                if ($commentsAllowed && $text !== '') {
                    $this->office->query(Comment::class, $company)->create([...$base, 'text' => mb_substr($text, 0, self::MAX_COMMENT)]);
                }
            });
        } catch (QueryException $exception) {
            // Two taps at once: the unique rater row lets only one through.
            if ($this->office->query(Rater::class, $company)->where('round_id', $round->getKey())->where('rater_hash', $this->hash($round, $studentId, $user))
                ->where('teacher_id', $teacherId)->where('subject_id', $subjectId)->exists()) {
                throw FeedbackException::alreadyRated();
            }
            throw $exception;
        }
    }

    /** Whether this rating was given already (to answer a second tap without a second row). */
    public function given(Organization $company, string $roundId, string $studentId, User $user, string $teacherId, string $subjectId): bool
    {
        $round = $this->office->query(Round::class, $company)->whereKey($roundId)->first();

        return $round !== null && $this->office->query(Rater::class, $company)->where('round_id', $roundId)->where('rater_hash', $this->hash($round, $studentId, $user))
            ->where('teacher_id', $teacherId)->where('subject_id', $subjectId)->exists();
    }

    /** @return Collection<int, Question> */
    private function questions(Organization $company, Round $round): Collection
    {
        return $this->office->query(Question::class, $company)->where('round_id', $round->getKey())->orderBy('sort_order')->get();
    }

    /** Students answer for themselves, guardians for their child, as the round was opened. */
    private function mayAnswer(Round $round, string $relation): bool
    {
        return match ($round->who_rates) {
            'students' => $relation === 'self',
            'guardians' => $relation !== 'self',
            default => true,
        };
    }

    /**
     * Who rated, as a keyed hash: the same person, student and round give the
     * same value (one rating each); without the application's secret it
     * cannot be turned back into anyone.
     */
    private function hash(Round $round, string $studentId, User $user): string
    {
        return hash_hmac('sha256', "teacher_feedback|{$round->getKey()}|{$studentId}|{$user->getKey()}", (string) config('app.key'));
    }
}
