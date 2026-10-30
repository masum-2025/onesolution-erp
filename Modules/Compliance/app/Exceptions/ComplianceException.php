<?php

namespace Modules\Compliance\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Compliance errors: a translated message (compliance::compliance.errors.*)
 * that says what to do next, and a stable code.
 */
class ComplianceException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'compliance::compliance.errors.'.$this->errorCode;
    }

    public static function notFactory(): self
    {
        return new self('not_factory', 422, [], ['field' => 'unit_id']);
    }

    public static function notListOwner(): self
    {
        return new self('not_list_owner', 422);
    }

    public static function auditNotFound(): self
    {
        return new self('audit_not_found', 404);
    }

    public static function itemNotFound(): self
    {
        return new self('item_not_found', 404);
    }

    public static function alreadyInRegister(): self
    {
        return new self('already_in_register', 409, [], ['field' => 'service_id']);
    }

    public static function codeTaken(): self
    {
        return new self('code_taken', 422, [], ['field' => 'code']);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 409);
    }

    public static function datesOrder(): self
    {
        return new self('dates_order', 422, [], ['field' => 'expires_on']);
    }

    public static function importColumns(string $columns): self
    {
        return new self('import_columns', 422, ['columns' => $columns], ['field' => 'file']);
    }

    public static function importUnknownColumns(string $columns): self
    {
        return new self('import_unknown_columns', 422, ['columns' => $columns], ['field' => 'file']);
    }

    public static function importTooManyRows(int $max): self
    {
        return new self('import_too_many_rows', 422, ['max' => $max], ['field' => 'file']);
    }

    public static function importUnreadable(): self
    {
        return new self('import_unreadable', 422, [], ['field' => 'file']);
    }

    public static function importEmpty(): self
    {
        return new self('import_empty', 422, [], ['field' => 'file']);
    }

    public static function importHasErrors(int $count): self
    {
        return new self('import_has_errors', 422, ['count' => $count], ['field' => 'file']);
    }

    public static function datesNeedPermission(): self
    {
        return new self('dates_need_permission', 403);
    }

    /** A provider of the lists links only to a connection where this client is the client. */
    public static function connectionNotYours(): self
    {
        return new self('connection_not_yours', 422, [], ['field' => 'connection_id']);
    }

    /** A renewal request goes to the line's provider: there must be one. */
    public static function noProvider(): self
    {
        return new self('no_provider', 422, [], ['field' => 'provider_id']);
    }

    public static function requestOpen(): self
    {
        return new self('request_open', 409);
    }

    public static function requestNotFound(): self
    {
        return new self('request_not_found', 404);
    }

    public static function requestClosed(): self
    {
        return new self('request_closed', 409);
    }

    public static function resultNotFound(): self
    {
        return new self('result_not_found', 404);
    }

    public static function resultDecided(): self
    {
        return new self('result_decided', 409);
    }

    /** The result's audit service is not in the lists at the factory. */
    public static function resultServiceUnknown(string $code): self
    {
        return new self('result_service_unknown', 422, ['code' => $code]);
    }
}
