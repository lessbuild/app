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
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $exportId) {}

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

    public function failed(?Throwable $exception): void
    {
        AnalyticsExport::query()->whereKey($this->exportId)->update([
            'status' => 'failed',
            'failure_message' => str($exception?->getMessage())->limit(500)->toString(),
        ]);
    }
}
