<?php

namespace App\Platform\Legal\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class LegalException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'legal.errors.'.$this->errorCode;
    }

    public static function unknownKind(): self
    {
        return new self('unknown_kind', 404);
    }

    public static function noDocument(): self
    {
        return new self('no_document', 404);
    }

    public static function outdated(int $current): self
    {
        return new self('outdated', 409, ['version' => (string) $current], ['current_version' => $current]);
    }

    public static function ownersOnly(): self
    {
        return new self('owners_only', 403);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }
}
