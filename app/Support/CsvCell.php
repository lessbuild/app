<?php

namespace App\Support;

final class CsvCell
{
    /**
     * Preserve the access-request export's existing single-line cell format.
     *
     * @param  mixed  $value  A value cast to text; null becomes an empty string.
     * @return string Newlines flattened to spaces, with leading formula/tab characters escaped.
     */
    public static function singleLine(mixed $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', (string) $value);

        return preg_match('/^[=+\-@\t]/', $value) ? "'".$value : $value;
    }

    /**
     * Prepare untrusted cell content before passing it to fputcsv.
     *
     * @param  string|int|null  $value  Exported content; null remains an empty optional cell.
     * @return ($value is null ? null : string) NUL-free content with spreadsheet formulas prefixed by an apostrophe.
     */
    public static function escape(string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', (string) $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
