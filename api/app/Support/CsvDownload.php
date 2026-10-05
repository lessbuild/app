<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** A private CSV download: UTF-8 with a byte-order mark (so spreadsheets read accents), a header row, then the rows. */
final class CsvDownload
{
    /**
     * Stream rows as a CSV file. Text that a spreadsheet could take for a formula is made safe.
     *
     * @param  string  $filename  The file's name, ending .csv.
     * @param  list<string>  $header  The column names.
     * @param  iterable<array<int, scalar|null>>  $rows  The rows, in the header's order.
     * @return StreamedResponse
     */
    public static function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $cell): string => is_string($cell) ? SpreadsheetCell::text($cell) : (string) $cell, $row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
