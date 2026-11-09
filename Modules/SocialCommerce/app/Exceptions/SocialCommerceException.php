<?php

namespace Modules\SocialCommerce\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Facebook selling errors: a translated message (social_commerce::social.errors.*)
 * that says what to do next, and a stable code.
 */
class SocialCommerceException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'social_commerce::social.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function notFound(string $what): self
    {
        return new self("{$what}_not_found", 404);
    }

    public static function connectExpired(): self
    {
        return new self('connect_expired', 410);
    }

    public static function noPagesChosen(): self
    {
        return new self('no_pages_chosen', 422);
    }

    public static function pageTaken(string $page): self
    {
        return new self('page_taken', 409, ['page' => $page]);
    }

    public static function pageUnusable(string $page): self
    {
        return new self('page_unusable', 409, ['page' => $page]);
    }

    public static function wrongStatus(string $action, string $status): self
    {
        return new self('wrong_status', 409, ['action' => $action, 'status' => $status]);
    }

    public static function emptyPost(): self
    {
        return new self('empty_post', 422);
    }

    public static function tooManyImages(int $max): self
    {
        return new self('too_many_images', 422, ['max' => (string) $max]);
    }

    public static function imageTooLarge(int $mb): self
    {
        return new self('image_too_large', 422, ['mb' => (string) $mb]);
    }

    public static function notAnImage(): self
    {
        return new self('not_an_image', 422);
    }

    public static function tooSoon(int $minutes): self
    {
        return new self('too_soon', 422, ['minutes' => (string) $minutes]);
    }

    public static function unknownItem(): self
    {
        return new self('unknown_item', 422);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 409);
    }
}
