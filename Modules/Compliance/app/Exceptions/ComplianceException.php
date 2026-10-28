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
}
