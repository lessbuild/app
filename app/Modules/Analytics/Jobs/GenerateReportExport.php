<?php

namespace App\Modules\Analytics\Jobs;

use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $exportId)
    {
        $this->connection = 'analytics';
        $this->queue = 'analytics';
    }

    public function handle(OverviewReport $report): void
    {
        $export = ReportExport::query()->with('site')->find($this->exportId);

        if (! $export || $export->status === 'completed' || CarbonImmutable::parse($export->expires_at)->isPast()) {
            return;
        }

        if (! $this->requesterCanAccess($export)) {
            $export->update(['status' => 'failed', 'failure_message' => 'The requester no longer has access to this Analytics site.']);

            return;
        }

        $export->update(['status' => 'processing', 'failure_message' => null]);

        try {
            $filters = $export->filters ?? [];
            $summary = $report->for($export->site, (int) ($filters['days'] ?? 30), $filters);
            $path = 'exports/'.$export->id.'-'.now()->format('YmdHis').'.csv';
            $handle = fopen('php://temp', 'w+');

            if ($handle === false) {
                throw new RuntimeException('The report export could not be prepared.');
            }

            try {
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
                $contents = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }

            if (! $this->requesterCanAccess($export)) {
                throw new RuntimeException('The requester no longer has access to this Analytics site.');
            }

            if ($contents === false || ! Storage::disk('analytics-local')->put($path, $contents)) {
                throw new RuntimeException('The report export file could not be saved.');
            }

            if (! $this->requesterCanAccess($export)) {
                Storage::disk('analytics-local')->delete($path);

                throw new RuntimeException('The requester no longer has access to this Analytics site.');
            }

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

    private function requesterCanAccess(ReportExport $export): bool
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('analytics')) {
            return true;
        }

        $requester = $export->requester()->first();
        $site = $export->site()->first();
        $principal = $requester === null ? null : app(ResolvePlatformUser::class)->resolve($requester, 'analytics');

        return $principal !== null && $site !== null
            && (string) $site->workspace_id === (string) $export->workspace_id
            && app(SitePolicy::class)->manage($principal, $site);
    }
}
