<?php

namespace App\Platform\Portal\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Portal errors (Phase 5C-4), each telling the person what to do next:
 * lang/{locale}/portal.php "errors.*".
 */
class PortalException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'portal.errors.'.$this->errorCode;
    }

    public static function kindNotAvailable(): self
    {
        return new self('kind_not_available', 422, [], ['field' => 'subject_type']);
    }

    public static function recordNotFound(): self
    {
        return new self('record_not_found', 404);
    }

    public static function relationNotAllowed(): self
    {
        return new self('relation_not_allowed', 422, [], ['field' => 'relation']);
    }

    public static function badContact(string $field): self
    {
        return new self('bad_contact', 422, [], ['field' => $field]);
    }

    public static function invitationNotFound(): self
    {
        return new self('invitation_not_found', 404);
    }

    public static function invitationClosed(): self
    {
        return new self('invitation_closed', 410);
    }

    public static function contactMismatch(string $masked, string $channel): self
    {
        return new self("contact_mismatch_{$channel}", 422, ['contact' => $masked], ['contact' => $masked]);
    }

    public static function accountExists(): self
    {
        return new self('account_exists', 409);
    }

    public static function alreadyStaff(): self
    {
        return new self('already_staff', 409);
    }

    public static function tooManyLinks(int $max): self
    {
        return new self('too_many_links', 422, ['max' => (string) $max]);
    }

    public static function linkNotFound(): self
    {
        return new self('link_not_found', 404);
    }

    public static function linkNotPending(): self
    {
        return new self('link_not_pending', 422);
    }
}
