<?php

namespace App\Platform\Localization\Enums;

/**
 * Where a text is shown: the browser app (JSON files, {name} placeholders)
 * or the server (messages, emails, PHP files, :name placeholders).
 */
enum Channel: string
{
    case Ui = 'ui';
    case Server = 'server';

    /** The placeholder names a text uses: {name} in the browser, :name on the server. */
    public function placeholders(string $text): array
    {
        $pattern = $this === self::Ui ? '/\{(\w+)\}/' : '/:([A-Za-z_][A-Za-z0-9_]*)/';
        preg_match_all($pattern, $text, $matches);

        return array_values(array_unique($matches[1]));
    }
}
