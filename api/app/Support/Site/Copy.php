<?php

declare(strict_types=1);

namespace App\Support\Site;

/** Translates the public site's copy (config/marketing.php and the like) for the person's language, as the pages show it. */
final class Copy
{
    /**
     * Keys whose values are names the page uses (icons, colours), not words to translate.
     *
     * @var list<string>
     */
    private const KEEP = ['icon', 'accent', 'status_tone', 'tone', 'key', 'slug', 'date', 'group'];

    /**
     * Translate every piece of text in a copy array, at any depth, leaving icon and colour names as they are.
     *
     * @param  array<array-key, mixed>  $copy
     * @return array<array-key, mixed>
     */
    public static function translate(array $copy): array
    {
        $translated = [];
        foreach ($copy as $key => $value) {
            $translated[$key] = match (true) {
                is_array($value) => self::translate($value),
                is_string($value) && ! in_array($key, self::KEEP, true) => __($value),
                default => $value,
            };
        }

        return $translated;
    }
}
