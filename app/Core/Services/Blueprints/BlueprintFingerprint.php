<?php

namespace App\Core\Services\Blueprints;

final class BlueprintFingerprint
{
    public static function make(array $value): string
    {
        return hash('sha256', json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function ordered(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ordered($item);
            }
        }

        return $value;
    }
}
