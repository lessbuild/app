<?php

declare(strict_types=1);

namespace App\Support;

use Locale;

/** Names and flags for ISO 3166 alpha-2 country codes. */
final class Country
{
    /**
     * Get a country's flag and name in the app's language, such as "🇩🇪 Germany"; anything that isn't a country code
     * is returned as it is.
     *
     * @param  string  $code
     * @return string
     */
    public static function label(string $code): string
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return $code;
        }
        $name = Locale::getDisplayRegion('-'.$code, app()->getLocale());

        return self::flag($code).' '.($name !== '' && $name !== $code ? $name : $code);
    }

    /**
     * Get a country's flag emoji, made from the regional indicator letters of its code.
     *
     * @param  string  $code  two capital letters
     * @return string
     */
    public static function flag(string $code): string
    {
        return implode('', array_map(fn (string $letter): string => mb_chr(0x1F1E6 + ord($letter) - ord('A')), str_split($code)));
    }
}
