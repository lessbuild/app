<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Models\AnalyticsExport;
use App\Queries\Analytics\AnalyticsReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two attempts, since building a large report can time out once.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Create a new GenerateReportExport instance.
     *
     * Builds the CSV for an analytics report export.
     *
     * @param  int  $exportId  The requested export.
     */
    public function __construct(public int $exportId) {}

    /**
     * Run the report with the export's filters and writes each metric and breakdown row to a CSV on the local disk.
     * Finished or expired exports are skipped.
     *
     * @param  AnalyticsReportQuery  $report
     * @return void
     */
    public function handle(AnalyticsReportQuery $report): void
    {
        $export = AnalyticsExport::query()->with('site')->find($this->exportId);

        if (! $export || $export->status === 'completed' || CarbonImmutable::parse($export->expires_at)->isPast()) {
            return;
        }

        $export->update(['status' => 'processing', 'failure_message' => null]);

        try {
            $filters = $export->filters ?? [];
            $summary = $report->handle($export->site, (int) ($filters['days'] ?? 30), $filters);
            $path = 'exports/'.$export->id.'-'.now()->format('YmdHis').'.csv';
            $handle = fopen('php://temp', 'w+');
            if ($handle === false) {
                throw new RuntimeException('Could not open a temporary stream for the export.');
            }
            fputcsv($handle, ['section', 'label', 'value']);
            foreach ($summary['metrics'] as $metric) {
                fputcsv($handle, ['metrics', $metric['label'], $metric['value']]);
            }
            foreach ([
                'pages' => $summary['pages'],
                'entry_pages' => $summary['entryPages'],
                'exit_pages' => $summary['exitPages'],
                'sources' => $summary['sources'],
                'devices' => $summary['devices'],
                'browsers' => $summary['browsers'],
                'operating_systems' => $summary['operatingSystems'],
                'campaigns' => $summary['campaigns'],
            ] as $section => $items) {
                foreach ($items as $item) {
                    fputcsv($handle, [$section, $item['label'], $item['value']]);
                }
            }
            rewind($handle);
            Storage::disk('local')->put($path, (string) stream_get_contents($handle));
            fclose($handle);

            $export->update(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $export->update(['status' => 'failed', 'failure_message' => str($exception->getMessage())->limit(500)->toString()]);
            throw $exception;
        }
    }

    /**
     * Mark the export failed with the reason.
     *
     * @param  Throwable|null  $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        AnalyticsExport::query()->whereKey($this->exportId)->update([
            'status' => 'failed',
            'failure_message' => str($exception?->getMessage())->limit(500)->toString(),
        ]);
    }
}
