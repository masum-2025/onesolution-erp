<?php

namespace App\Platform\Tenancy\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base for platform errors. Renders a translated message plus a stable
 * machine-readable code, and never includes internal details.
 */
abstract class TenancyException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     * @param  array<string, mixed>  $extra  Additional safe response fields.
     */
    public function __construct(
        protected string $errorCode,
        protected int $status,
        protected array $replace = [],
        protected array $extra = [],
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

    protected function translationKey(): string
    {
        return 'tenancy.errors.'.$this->errorCode;
    }

    /** The translated message people see (API responses, console commands). */
    public function userMessage(): string
    {
        return __($this->translationKey(), $this->replace);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->userMessage(),
            'code' => $this->errorCode,
            ...$this->extra,
        ], $this->status);
    }
}
