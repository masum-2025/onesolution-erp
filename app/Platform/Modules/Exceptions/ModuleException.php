<?php

namespace App\Platform\Modules\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class ModuleException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'modules.errors.'.$this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('module_not_found', 404);
    }

    public static function disabled(string $module): self
    {
        return new self('module_disabled', 403, ['module' => $module]);
    }

    public static function notInPlan(string $module): self
    {
        return new self('not_in_plan', 422, ['module' => $module]);
    }

    public static function sectorNotAllowed(string $module): self
    {
        return new self('sector_not_allowed', 422, ['module' => $module]);
    }

    public static function consentRequired(string $module): self
    {
        return new self('consent_required', 422, ['module' => $module]);
    }

    public static function lockedByParent(string $module, string $organization): self
    {
        return new self('locked_by_parent', 422, ['module' => $module, 'organization' => $organization]);
    }

    public static function coreModule(string $module): self
    {
        return new self('core_module', 422, ['module' => $module]);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function dependentsNeedConfirmation(string $module, array $keys, string $names): self
    {
        return new self('dependents_need_confirmation', 409, ['module' => $module, 'modules' => $names], ['dependents' => $keys]);
    }

    public static function consentNotApplicable(string $module): self
    {
        return new self('consent_not_applicable', 422, ['module' => $module]);
    }

    public static function consentNotFound(): self
    {
        return new self('consent_not_found', 404);
    }

    public static function purgeConfirmMismatch(string $key): self
    {
        return new self('purge_confirm_mismatch', 422, ['key' => $key]);
    }

    public static function purgeRequiresDisabled(string $module): self
    {
        return new self('purge_requires_disabled', 422, ['module' => $module]);
    }

    public static function purgeAlreadyPending(): self
    {
        return new self('purge_already_pending', 422);
    }

    public static function purgeNotFound(): self
    {
        return new self('purge_not_found', 404);
    }
}
