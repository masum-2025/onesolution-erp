<?php

namespace App\Platform\Notifications\Services;

/**
 * SMS length rules: plain GSM text fits 160 characters (153 per part when
 * split); anything else, Bangla included, is Unicode: 70 (67 per part).
 * Printable ASCII and the common Latin letters count as GSM here.
 */
final class SmsText
{
    public static function isUnicode(string $text): bool
    {
        return preg_match('/^[\x{0A}\x{0D}\x{20}-\x{7E}£¥èéùìòÇØøÅåÆæßÉÄÖÑÜ§¿äöñüà¡€]*$/u', $text) !== 1;
    }

    public static function segments(string $text): int
    {
        $length = mb_strlen($text);
        [$single, $part] = self::isUnicode($text) ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $part);
    }
}
