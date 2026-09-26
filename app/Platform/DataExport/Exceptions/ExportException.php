<?php

namespace App\Platform\DataExport\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class ExportException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'exports.errors.'.$this->errorCode;
    }

    public static function alreadyRunning(): self
    {
        return new self('already_running', 422);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    public static function notReady(): self
    {
        return new self('not_ready', 422);
    }
}
