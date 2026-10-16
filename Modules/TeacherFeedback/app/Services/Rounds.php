<?php

namespace Modules\TeacherFeedback\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\TeacherFeedback\Exceptions\FeedbackException;
use Modules\TeacherFeedback\Models\Comment;
use Modules\TeacherFeedback\Models\Question;
use Modules\TeacherFeedback\Models\Round;

/**
 * Feedback rounds: made as a draft with its questions (the ready-made ones
 * when none are given), opened (who rates is taken from the rule then and
 * kept), closed. Questions change only while a round is a draft; once open
 * only its last day may move. Comments are approved or hidden by someone
 * who manages feedback.
 */
class Rounds
{
    /** The ready-made questions (teacher_feedback::feedback.default_questions.*). */
    public const DEFAULT_QUESTIONS = ['explains', 'on_time', 'answers', 'manner', 'homework'];

    public function __construct(
        private FeedbackOffice $office,
        private AcademicDirectory $academic,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** @return Collection<int, Question> */
    public function questions(Organization $company, Round $round): Collection
    {
        return $this->office->query(Question::class, $company)->where('round_id', $round->getKey())->orderBy('sort_order')->get();
    }

    /**
     * Make (round null) or change a round.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Organization $company, Organization $unit, ?Round $round, array $data, User $actor): Round
    {
        if ($round === null && $this->academic->session($company, $data['session_id']) === null) {
            throw ValidationException::withMessages(['session_id' => __('teacher_feedback::feedback.validation.session')]);
        }

        return $this->office->transaction($company, function () use ($company, $unit, $round, $data, $actor) {
            if ($round !== null) {
                $round = $this->office->query(Round::class, $company)->whereKey($round->getKey())->lockForUpdate()->firstOrFail();
                if ($round->version !== (int) $data['base_version']) {
                    throw FeedbackException::versionConflict(['version' => $round->version]);
                }
                // Once open, only the last day may move (people may already have answered the questions).
                if ($round->status === 'closed' || ($round->status === 'open' && array_intersect_key($data, array_flip(['name', 'opens_on', 'questions'])) !== [])) {
                    throw FeedbackException::wrongStatus();
                }
                $old = $this->dated($round->only(['name', 'opens_on', 'closes_on']));
                $round->fill(array_intersect_key($data, array_flip(['name', 'opens_on', 'closes_on'])));
                $round->version++;
            } else {
                $old = null;
                $round = new Round([
                    'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'session_id' => $data['session_id'],
                    'name' => $data['name'], 'opens_on' => $data['opens_on'], 'closes_on' => $data['closes_on'], 'created_by' => $actor->getKey(),
                ]);
            }
            if ($round->opens_on > $round->closes_on) {
                throw ValidationException::withMessages(['closes_on' => __('teacher_feedback::feedback.validation.dates')]);
            }
            $round->save();

            if ($old === null || array_key_exists('questions', $data)) {
                $texts = $data['questions'] ?? null;
                $texts ??= array_map(fn (string $key) => [
                    'en' => __("teacher_feedback::feedback.default_questions.{$key}", [], 'en'),
                    'bn' => __("teacher_feedback::feedback.default_questions.{$key}", [], 'bn'),
                ], self::DEFAULT_QUESTIONS);
                $this->office->query(Question::class, $company)->where('round_id', $round->getKey())->delete();
                foreach (array_values($texts) as $order => $text) {
                    $question = new Question(['organization_id' => $company->getKey(), 'round_id' => $round->getKey(), 'sort_order' => $order]);
                    $question->putTexts('text', $text);
                    $question->save();
                }
            }

            $this->audit->record(
                $old === null ? 'teacher_feedback.round_created' : 'teacher_feedback.round_updated',
                $round,
                old: $old ?? [],
                new: $this->dated($round->only(['name', 'session_id', 'opens_on', 'closes_on'])),
                actor: $actor,
                organizationId: $company->getKey(),
            );

            return $round;
        });
    }

    /** Open a draft: it needs questions; who rates is taken from the rule now and kept. */
    public function open(Organization $company, Organization $unit, Round $round, int $baseVersion, User $actor): Round
    {
        if ($this->questions($company, $round)->isEmpty()) {
            throw FeedbackException::noQuestions();
        }
        $who = (string) $this->rules->get('teacher_feedback.who_rates', $this->contexts->forOrganization($unit));

        return $this->move($company, $round, $baseVersion, 'draft', 'open', $actor, 'teacher_feedback.round_opened', ['who_rates' => $who]);
    }

    public function close(Organization $company, Round $round, int $baseVersion, User $actor): Round
    {
        return $this->move($company, $round, $baseVersion, 'open', 'closed', $actor, 'teacher_feedback.round_closed');
    }

    /** Approve a comment (the teacher sees it then) or hide it (nobody but who manages does). */
    public function decide(Organization $company, Comment $comment, string $status, User $actor): Comment
    {
        $old = $comment->status;
        $comment->status = $status;
        $comment->decided_by = $actor->getKey();
        $comment->save();
        // The words stay out of the log: they are anonymous, the decision is not.
        $this->audit->record('teacher_feedback.comment_'.$status, null, old: ['status' => $old], new: ['status' => $status, 'round_id' => $comment->round_id, 'teacher_id' => $comment->teacher_id], actor: $actor, organizationId: $company->getKey());

        return $comment;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(Organization $company, Round $round, int $baseVersion, string $from, string $to, User $actor, string $action, array $extra = []): Round
    {
        return $this->office->transaction($company, function () use ($company, $round, $baseVersion, $from, $to, $actor, $action, $extra) {
            $round = $this->office->query(Round::class, $company)->whereKey($round->getKey())->lockForUpdate()->firstOrFail();
            if ($round->version !== $baseVersion) {
                throw FeedbackException::versionConflict(['version' => $round->version]);
            }
            if ($round->status !== $from) {
                throw FeedbackException::wrongStatus();
            }
            $round->fill(['status' => $to, ...$extra]);
            $round->version++;
            $round->save();
            $this->audit->record($action, $round, old: ['status' => $from], new: ['status' => $to, ...$extra], actor: $actor, organizationId: $company->getKey());

            return $round;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function dated(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $values);
    }
}
