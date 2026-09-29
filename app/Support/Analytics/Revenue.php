<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Support\Facades\DB;
use NumberFormatter;

/** Reads and formats the revenue custom events can carry (`track('purchase', {revenue: 49.99, currency: 'EUR'})`). */
final class Revenue
{
    /**
     * Get a SQL expression for an event's revenue amount, or NULL when it has none.
     *
     * @param  literal-string  $table  the events table or its alias
     * @return literal-string
     */
    public static function amount(string $table): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "CAST({$table}.properties->>'revenue' AS NUMERIC)"
            : "CAST(json_extract({$table}.properties, '$.revenue') AS REAL)";
    }

    /**
     * Get a SQL expression for an event's revenue currency.
     *
     * @param  literal-string  $table  the events table or its alias
     * @return literal-string
     */
    public static function currency(string $table): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "COALESCE({$table}.properties->>'currency', 'USD')"
            : "COALESCE(json_extract({$table}.properties, '$.currency'), 'USD')";
    }

    /**
     * Write amounts in their currencies, largest first, such as "€1,234.50 · $20.00", or null when there are none.
     *
     * @param  array<string, float>  $amounts  keyed by currency code
     * @return string|null
     */
    public static function format(array $amounts): ?string
    {
        $amounts = array_filter($amounts, fn (float $amount): bool => $amount > 0);
        if ($amounts === []) {
            return null;
        }
        arsort($amounts);
        $formatter = new NumberFormatter(str_replace('-', '_', app()->getLocale()), NumberFormatter::CURRENCY);
        $parts = [];
        foreach ($amounts as $currency => $amount) {
            $formatted = $formatter->formatCurrency($amount, (string) $currency);
            $parts[] = $formatted !== false ? $formatted : $currency.' '.number_format($amount, 2);
        }

        return implode(' · ', $parts);
    }
}
