<?php

namespace App\Platform\Notifications\Services;

/**
 * Addresses as they may appear in logs and delivery records: enough to
 *
 * recognise, not enough to use. jane.doe@example.com -> j*******@example.com;
 * +8801712345645 -> +88017*******45.
 */
final class Mask
{
    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }

    public static function phone(string $phone): string
    {
        $length = strlen($phone);

        return $length <= 7 ? str_repeat('*', $length) : substr($phone, 0, 6).str_repeat('*', $length - 8).substr($phone, -2);
    }
}
