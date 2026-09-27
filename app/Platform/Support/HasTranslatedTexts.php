<?php

namespace App\Platform\Support;

use Spatie\Translatable\HasTranslations;

/**
 * Data labels in several languages (spatie/laravel-translatable), stored as
 * JSON {"en": "...", "bn": "...", "ar": "..."} in one column.
 *
 * - $model->name is the text in the current language (falls back to the
 *   app fallback language, then any language that exists);
 * - texts('name') gives every language (for forms, audit and API);
 * - putTexts('name', [...]) replaces the whole set; null clears the column.
 * - API output (toArray) keeps every language, as before.
 *
 * @property array<int, string> $translatable
 */
trait HasTranslatedTexts
{
    use HasTranslations;

    /**
     * @return array<string, string>
     */
    public function texts(string $key): array
    {
        return $this->getTranslations($key);
    }

    /**
     * @param  array<string, string>|null  $texts
     */
    public function putTexts(string $key, ?array $texts): static
    {
        if ($texts === null) {
            $this->attributes[$key] = null;

            return $this;
        }

        return $this->replaceTranslations($key, $texts);
    }

    /** The text in a language, with the same fallbacks as $model->{$key}. */
    public function textIn(string $key, ?string $locale = null): string
    {
        return (string) $this->getTranslation($key, $locale ?? app()->getLocale());
    }
}
