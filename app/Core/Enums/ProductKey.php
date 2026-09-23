<?php

namespace App\Core\Enums;

enum ProductKey: string
{
    case Deployer = 'deployer';
    case Monitor = 'monitor';
    case Analytics = 'analytics';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $product): string => $product->value, self::cases());
    }
}
