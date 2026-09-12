<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepositoryWebhookDeliveryHistoryExporter
{
    public function __construct(private readonly RepositoryWebhookDeliveryHistoryQuery $deliveries) {}

    /**
     * Stream a repository's filtered webhook delivery and build outcomes as private CSV.
     *
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     */
    public function stream(Repository $repository, array $filters): StreamedResponse
    {
        $filename = "lessbuild-repository-{$repository->id}-webhook-deliveries-"
            .now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($repository, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Delivery ID',
                'Status',
                'Revision',
                'Commit message',
                'Build ID',
                'Build status',
                'Received at',
                'Updated at',
            ], ',', '"', '');

            $this->deliveries->for($repository, $filters)
                ->with('build')
                ->latest('id')
                ->lazy(250)
                ->each(function (RepositoryWebhookDelivery $delivery) use ($output): void {
                    fputcsv($output, [
                        $this->csvCell($delivery->delivery_id),
                        $this->csvCell($delivery->status),
                        $this->csvCell($delivery->revision),
                        $this->csvCell($delivery->commit_message),
                        $delivery->build_id,
                        $this->csvCell($delivery->build?->status),
                        $delivery->created_at?->toIso8601String(),
                        $delivery->updated_at?->toIso8601String(),
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
