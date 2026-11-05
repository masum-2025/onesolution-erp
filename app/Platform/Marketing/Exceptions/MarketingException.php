<?php

namespace App\Platform\Marketing\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class MarketingException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'marketing.errors.'.$this->errorCode;
    }

    public static function noSite(): self
    {
        return new self('no_site', 404);
    }

    public static function siteExists(): self
    {
        return new self('site_exists', 409);
    }

    public static function changedMeanwhile(): self
    {
        return new self('changed_meanwhile', 409);
    }

    public static function pageNotFound(): self
    {
        return new self('page_not_found', 404);
    }

    public static function blockNotFound(): self
    {
        return new self('block_not_found', 404);
    }

    public static function slugTaken(): self
    {
        return new self('slug_taken', 422, [], ['field' => 'slug']);
    }

    public static function slugReserved(): self
    {
        return new self('slug_reserved', 422, [], ['field' => 'slug']);
    }

    public static function homeStays(): self
    {
        return new self('home_stays', 422);
    }

    public static function nothingToPublish(): self
    {
        return new self('nothing_to_publish', 422);
    }

    public static function versionNotFound(): self
    {
        return new self('version_not_found', 404);
    }

    public static function pricingHouseOnly(): self
    {
        return new self('pricing_house_only', 422, [], ['field' => 'type']);
    }

    public static function mainLocaleMissing(): self
    {
        return new self('main_locale_missing', 422, [], ['field' => 'default_locale']);
    }
}
