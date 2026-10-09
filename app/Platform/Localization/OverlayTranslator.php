<?php

namespace App\Platform\Localization;

use Illuminate\Database\QueryException;
use Illuminate\Translation\Translator;

/**
 * Laravel's translator with the database on top: a text worded by the
 * platform, the partner, the group or the company wins over the file. A
 * database language without a text falls back to its file language.
 */
class OverlayTranslator extends Translator
{
    public static function wrap(Translator $translator): self
    {
        $wrapped = new self($translator->getLoader(), $translator->getLocale());
        $wrapped->setFallback($translator->getFallback());

        return $wrapped;
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $locale = $locale ?: $this->locale;

        if (is_string($key) && app()->bound(ServerOverlay::class)) {
            try {
                $overlay = app(ServerOverlay::class);
                $text = $overlay->text($locale, $key);
                if ($text !== null) {
                    return $this->makeReplacements($text, $replace);
                }

                $languageFallback = $fallback ? $overlay->fallbackOf($locale) : null;
                if ($languageFallback !== null) {
                    $text = $overlay->text($languageFallback, $key);

                    return $text !== null ? $this->makeReplacements($text, $replace) : parent::get($key, $replace, $languageFallback, $fallback);
                }
            } catch (QueryException) {
                // No database yet (installing, migrating): the files alone.
            }
        }

        return parent::get($key, $replace, $locale, $fallback);
    }
}
