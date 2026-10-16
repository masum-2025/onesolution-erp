<?php

namespace Modules\TeacherFeedback\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Teacher feedback errors: a translated message (teacher_feedback::feedback.errors.*)
 * that says what to do next, and a stable code.
 */
class FeedbackException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'teacher_feedback::feedback.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }

    public static function wrongStatus(): self
    {
        return new self('wrong_status', 409);
    }

    /** The round is not open today. */
    public static function roundClosed(string $opens, string $closes): self
    {
        return new self('round_closed', 409, ['opens' => $opens, 'closes' => $closes]);
    }

    /** Guardians or students rate, not both (rule teacher_feedback.who_rates). */
    public static function notYourTurn(): self
    {
        return new self('not_your_turn', 403);
    }

    /** Only a teacher who teaches the student, for that subject. */
    public static function notTheirTeacher(): self
    {
        return new self('not_their_teacher', 422, [], ['field' => 'teacher_id']);
    }

    public static function alreadyRated(): self
    {
        return new self('already_rated', 409);
    }

    public static function unansweredQuestions(): self
    {
        return new self('unanswered_questions', 422, [], ['field' => 'answers']);
    }

    public static function noQuestions(): self
    {
        return new self('no_questions', 422, [], ['field' => 'questions']);
    }
}
