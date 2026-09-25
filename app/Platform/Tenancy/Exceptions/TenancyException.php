<?php

namespace App\Platform\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base for tenancy errors. Renders a translated message plus a stable
 * machine-readable code, and never includes ids or internal details.
 */
abstract class TenancyException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        protected string $errorCode,
        protected int $status,
        protected array $replace = [],
    ) {
        parent::__construct($errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('tenancy.errors.'.$this->errorCode, $this->replace),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
