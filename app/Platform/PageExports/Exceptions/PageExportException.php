<?php

namespace App\Platform\PageExports\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/** Export or print refused on a module page: a translated message and a stable code. */
class PageExportException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'exports.errors.'.$this->errorCode;
    }

    public static function off(): self
    {
        return new self('page_export_off', 403);
    }

    public static function formatOff(): self
    {
        return new self('page_format_off', 403);
    }

    public static function tooManyRows(int $max): self
    {
        return new self('page_too_many_rows', 422, ['max' => $max], ['max_rows' => $max]);
    }
}
