<?php

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $connection = 'analytics';

    public string $queue = 'analytics';

    public int $tries = 2;

    public function __construct(public int $exportId) {}

    public function handle(OverviewReport $report): void
    {
        $export = ReportExport::query()->with('site')->find($this->exportId);

        if (! $export || $export->status === 'completed' || CarbonImmutable::parse($export->expires_at)->isPast()) {
            return;
        }

        $export->update(['status' => 'processing', 'failure_message' => null]);

        try {
            $filters = $export->filters ?? [];
            $summary = $report->for($export->site, (int) ($filters['days'] ?? 30), $filters);
            $path = 'exports/'.$export->id.'-'.now()->format('YmdHis').'.csv';
            $handle = fopen('php://temp', 'w+');
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
            Storage::disk('analytics-local')->put($path, stream_get_contents($handle));
            fclose($handle);

            $export->update(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $export->update(['status' => 'failed', 'failure_message' => str($exception->getMessage())->limit(500)->toString()]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update([
            'status' => 'failed',
            'failure_message' => str($exception?->getMessage())->limit(500)->toString(),
        ]);
    }
}
