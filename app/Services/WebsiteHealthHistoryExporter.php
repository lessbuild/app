<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteHealthCheck;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteHealthHistoryExporter
{
    public function __construct(private readonly WebsiteHealthHistoryQuery $healthHistory) {}

    /**
     * Stream bounded, filtered website health-check history as a private, spreadsheet-safe CSV.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    public function stream(Website $website, array $filters): StreamedResponse
    {
        $filename = "lessbuild-website-{$website->id}-health-checks-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($website, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Check ID',
                'Result',
                'Source',
                'HTTP status',
                'Duration ms',
                'Endpoint',
                'Error',
                'Checked at',
            ], ',', '"', '');

            $this->healthHistory->for($website, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(WebsiteHealthCheck::MAX_PER_WEBSITE)
                ->get()
                ->each(function (WebsiteHealthCheck $check) use ($output): void {
                    fputcsv($output, [
                        $check->id,
                        $check->successful ? 'healthy' : 'failed',
                        $check->source,
                        $check->http_status,
                        $check->duration_ms,
                        $this->csvCell($check->endpoint),
                        $this->csvCell($check->error),
                        $check->checked_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Preserve null values and escape text that could be interpreted as a spreadsheet formula.
     */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
