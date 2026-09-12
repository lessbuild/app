<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderConnectionHistoryExporter
{
    public function __construct(private readonly ProviderConnectionHistoryQuery $history) {}

    /**
     * Stream the bounded, filtered provider connection history as a private CSV download.
     *
     * @param  array{result: ?string, source: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    public function stream(Provider $provider, array $filters): StreamedResponse
    {
        $filename = "lessbuild-provider-{$provider->id}-connection-checks-".now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($provider, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Check ID',
                'Result',
                'Source',
                'Provider type',
                'HTTP status',
                'Duration ms',
                'Endpoint',
                'Error',
                'Checked at',
            ], ',', '"', '');

            $this->history->for($provider, $filters)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
                ->get()
                ->each(function (ProviderConnectionCheck $check) use ($output): void {
                    fputcsv($output, [
                        $check->id,
                        $check->successful ? 'healthy' : 'failed',
                        $check->source,
                        $this->csvCell($check->provider_type),
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
