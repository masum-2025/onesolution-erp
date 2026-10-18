<?php

namespace Modules\Zkteco\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * ZKTeco errors: a translated message (zkteco::zkteco.errors.*) that says
 * what to do next, and a stable code.
 */
class ZktecoException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'zkteco::zkteco.errors.'.$this->errorCode;
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

    /** The terminal did not answer, or refused (wrong comm key…). The reason is a code, never the network's words. */
    public static function unreachable(string $reason): self
    {
        return new self('unreachable', 422, ['reason' => __("zkteco::zkteco.reasons.{$reason}")], ['reason' => $reason]);
    }

    /** An office bridge reads this device: the app cannot reach it from where it runs. */
    public static function readByBridge(): self
    {
        return new self('read_by_bridge', 409);
    }

    /** A device with logs is switched off, not removed. */
    public static function hasLogs(): self
    {
        return new self('has_logs', 409);
    }
}
