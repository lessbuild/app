<?php

declare(strict_types=1);

namespace App\Support;

/** Makes values safe to put in CSV files people open in spreadsheets. */
final class SpreadsheetCell
{
    /**
     * Prefix a value with `'` when a spreadsheet would treat it as a formula (it starts with `=`, `+`, `-`, `@`, a tab
     * or a carriage return), so something like `=HYPERLINK(…)` in a name or command stays plain text.
     *
     * @param  string  $value
     * @return string
     */
    public static function text(string $value): string
    {
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
