<?php

namespace Modules\CourseRegistration\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Course registration errors: a translated message
 * (course_registration::registration.errors.*) that says what to do next,
 * and a stable code.
 */
class RegistrationException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'course_registration::registration.errors.'.$this->errorCode;
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

    public static function wrongStatus(string $status): self
    {
        return new self('wrong_status', 409, ['status' => $status]);
    }

    /** Students register only while the session's window is open. */
    public static function windowClosed(?string $opens, ?string $closes): self
    {
        return new self($opens === null ? 'no_window' : 'window_closed', 409, ['opens' => (string) $opens, 'closes' => (string) $closes]);
    }

    /** After the add/drop date a subject is withdrawn (with a reason), not dropped. */
    public static function addDropOver(string $until): self
    {
        return new self('add_drop_over', 409, ['date' => $until]);
    }

    public static function alreadyRegistered(string $subject): self
    {
        return new self('already_registered', 409, ['subject' => $subject], ['field' => 'offering_id']);
    }

    /** @param  list<string>  $missing  Subject codes still to complete. */
    public static function prerequisitesMissing(string $subject, array $missing): self
    {
        return new self('prerequisites_missing', 422, ['subject' => $subject, 'missing' => implode(', ', $missing)], ['missing' => $missing, 'field' => 'offering_id']);
    }

    public static function offeringFull(int $capacity): self
    {
        return new self('offering_full', 409, ['capacity' => (string) $capacity], ['field' => 'offering_id']);
    }

    public static function offeringNotOpen(): self
    {
        return new self('offering_not_open', 409, [], ['field' => 'offering_id']);
    }

    /** The offering is for another session or another campus than the student's. */
    public static function offeringElsewhere(): self
    {
        return new self('offering_elsewhere', 422, [], ['field' => 'offering_id']);
    }

    public static function creditsOver(string $limit): self
    {
        return new self('credits_over', 422, ['limit' => $limit], ['field' => 'offering_id']);
    }

    public static function creditsUnder(string $minimum): self
    {
        return new self('credits_under', 422, ['minimum' => $minimum]);
    }

    public static function notStudying(string $name): self
    {
        return new self('not_studying', 409, ['name' => $name]);
    }

    public static function selfRegistrationOff(): self
    {
        return new self('self_registration_off', 403);
    }

    /** The student (or whoever handed it in) never approves their own registration. */
    public static function ownApproval(): self
    {
        return new self('own_approval', 403);
    }

    public static function reasonNeeded(): self
    {
        return new self('reason_needed', 422, [], ['field' => 'reason']);
    }
}
