<?php

namespace App\Platform\Localization\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

class LocalizationException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'languages.errors.'.$this->errorCode;
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403);
    }

    /** The module is off here, or the partner keeps one wording for all clients. */
    public static function ownWordingOff(): self
    {
        return new self('own_wording_off', 403);
    }

    /** Wording is kept at a group or a company, not at a branch or department. */
    public static function wrongLevel(): self
    {
        return new self('wrong_level', 422);
    }

    public static function unknownLanguage(): self
    {
        return new self('unknown_language', 404);
    }

    public static function languageNotOffered(): self
    {
        return new self('language_not_offered', 422, [], ['field' => 'locale']);
    }

    public static function badCode(): self
    {
        return new self('bad_code', 422, [], ['field' => 'code']);
    }

    public static function languageExists(): self
    {
        return new self('language_exists', 422, [], ['field' => 'code']);
    }

    public static function fileLanguage(): self
    {
        return new self('file_language', 422);
    }

    public static function belowMinimum(int $percent, int $needed): self
    {
        return new self('below_minimum', 422, ['percent' => (string) $percent, 'needed' => (string) $needed], ['field' => 'status']);
    }

    public static function onlyDraftRemovable(): self
    {
        return new self('only_draft_removable', 422);
    }

    public static function unknownKey(string $key): self
    {
        return new self('unknown_key', 422, ['key' => $key], ['field' => 'key', 'key' => $key]);
    }

    public static function markup(string $key): self
    {
        return new self('markup', 422, [], ['field' => 'value', 'key' => $key]);
    }

    /** @param list<string> $names */
    public static function unknownPlaceholders(string $key, array $names): self
    {
        return new self('unknown_placeholders', 422, ['names' => implode(', ', $names)], ['field' => 'value', 'key' => $key, 'placeholders' => $names]);
    }
}
