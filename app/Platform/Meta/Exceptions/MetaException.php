<?php

namespace App\Platform\Meta\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Meta errors (meta.errors.*): a translated message that says what to do
 * next and a stable code. Graph errors are reduced to a kind (token_invalid,
 * permission_missing, rate_limited, unreachable, rejected); Meta's own text
 * never carries tokens, but only its user-facing line is passed on.
 */
class MetaException extends TenancyException
{
    /** Kinds a Graph call can fail with. */
    public const TOKEN_INVALID = 'token_invalid';

    public const PERMISSION_MISSING = 'permission_missing';

    public const RATE_LIMITED = 'rate_limited';

    public const UNREACHABLE = 'unreachable';

    public const REJECTED = 'rejected';

    protected function translationKey(): string
    {
        return 'meta.errors.'.$this->errorCode;
    }

    /** What Meta said to show people (may be empty). */
    public function metaMessage(): string
    {
        return (string) ($this->extra['meta_message'] ?? '');
    }

    /** True when the page or app must be signed in again (token gone, permission taken back). */
    public function needsReconnect(): bool
    {
        return in_array($this->errorCode, [self::TOKEN_INVALID, self::PERMISSION_MISSING], true);
    }

    public static function graph(string $kind, string $metaMessage = ''): self
    {
        $message = mb_substr(trim($metaMessage), 0, 300);

        return new self($kind, $kind === self::UNREACHABLE ? 503 : 422, ['detail' => $message], $message === '' ? [] : ['meta_message' => $message]);
    }

    public static function noApp(): self
    {
        return new self('no_app', 409);
    }

    public static function appCheckFailed(): self
    {
        return new self('app_check_failed', 422);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 409);
    }

    public static function nothingPending(): self
    {
        return new self('nothing_pending', 409);
    }

    public static function ownChange(): self
    {
        return new self('own_change', 403);
    }

    public static function badSignedRequest(): self
    {
        return new self('bad_signed_request', 400);
    }
}
