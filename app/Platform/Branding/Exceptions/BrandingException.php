<?php

namespace App\Platform\Branding\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class BrandingException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'branding.errors.'.$this->errorCode;
    }

    public static function notAllowed(): self
    {
        return new self('not_allowed', 403);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403);
    }

    public static function contrast(string $problem): self
    {
        return new self("contrast_{$problem}", 422, [], ['field' => 'primary_color']);
    }

    public static function badImage(): self
    {
        return new self('bad_image', 422, [], ['field' => 'file']);
    }
}
