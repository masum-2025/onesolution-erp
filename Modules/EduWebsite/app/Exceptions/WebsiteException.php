<?php

namespace Modules\EduWebsite\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Website errors: a translated message (edu_website::website.errors.*) that
 * says what to do next, and a stable code.
 */
class WebsiteException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'edu_website::website.errors.'.$this->errorCode;
    }

    public static function notCompanyUnit(): self
    {
        return new self('not_company_unit', 422);
    }

    public static function noSite(): self
    {
        return new self('no_site', 404);
    }

    public static function slugTaken(): self
    {
        return new self('slug_taken', 409);
    }

    public static function templateClosed(): self
    {
        return new self('template_closed', 422);
    }

    public static function pageNotFound(): self
    {
        return new self('page_not_found', 404);
    }

    public static function blockNotFound(): self
    {
        return new self('block_not_found', 404);
    }

    public static function noticeNotFound(): self
    {
        return new self('notice_not_found', 404);
    }

    public static function mediaNotFound(): self
    {
        return new self('media_not_found', 404);
    }

    public static function tooManyPages(int $max): self
    {
        return new self('too_many_pages', 422, ['max' => (string) $max]);
    }

    public static function homeNeeded(): self
    {
        return new self('home_needed', 422);
    }

    public static function mediaInUse(): self
    {
        return new self('media_in_use', 409);
    }

    public static function ownDomainOff(): self
    {
        return new self('own_domain_off', 403);
    }

    public static function domainReserved(): self
    {
        return new self('domain_reserved', 422);
    }

    public static function domainTaken(): self
    {
        return new self('domain_taken', 409);
    }

    public static function noDomain(): self
    {
        return new self('no_domain', 422);
    }

    public static function domainNotProven(string $name, string $value): self
    {
        return new self('domain_not_proven', 422, ['name' => $name], ['record' => ['type' => 'TXT', 'name' => $name, 'value' => $value]]);
    }

    public static function enquiryNotFound(): self
    {
        return new self('enquiry_not_found', 404);
    }

    public static function tooManyEnquiries(): self
    {
        return new self('too_many_enquiries', 429);
    }

    public static function defaultLocaleOff(): self
    {
        return new self('default_locale_off', 422);
    }

    public static function versionConflict(array $current): self
    {
        return new self('version_conflict', 409, extra: ['current' => $current]);
    }
}
