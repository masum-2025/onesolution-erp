<?php

namespace App\Platform\PartnerApi\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class ApiException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'api.errors.'.$this->errorCode;
    }

    public static function invalidKey(): self
    {
        // Missing, wrong, revoked or expired: one answer, nothing to probe.
        return new self('invalid_key', 401);
    }

    public static function keyInactive(): self
    {
        return new self('key_inactive', 401);
    }

    public static function missingScope(string $scope): self
    {
        return new self('missing_scope', 403, ['scope' => $scope], ['scope' => $scope]);
    }

    public static function idempotencyMismatch(): self
    {
        return new self('idempotency_mismatch', 422);
    }

    public static function roleNotAllowed(): self
    {
        return new self('role_not_allowed', 403);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }
}
