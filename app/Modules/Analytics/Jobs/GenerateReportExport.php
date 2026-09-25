<?php

namespace App\Modules\Analytics\Jobs;

use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Modules\Analytics\Models\ReportExport;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Queries\Reporting\OverviewReport;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        $export = DB::connection('analytics')->transaction(function (): ?ReportExport {
            $candidate = ReportExport::query()->find($this->exportId);
            if (! $candidate) {
                return null;
            }
            DB::connection('analytics')->table('workspaces')->where('id', $candidate->workspace_id)->update(['id' => DB::raw('id')]);
            $workspace = Workspace::query()->whereKey($candidate->workspace_id)->lockForUpdate()->first();
            $export = ReportExport::query()->lockForUpdate()->with('site')->find($this->exportId);
            if (! $workspace || ! $export || $export->status !== 'pending') {
                return null;
            }
            if (CarbonImmutable::parse($export->expires_at)->isPast()) {
                $export->update(['status' => 'failed', 'failure_message' => 'The report export has expired.']);

                return null;
            }
            if (app(AnalyticsDeletionFence::class)->isFenced('workspace', (string) $export->workspace_id)) {
                $export->update(['status' => 'failed', 'failure_message' => 'The Analytics workspace is being deleted.']);

                return null;
            }
            if (! $this->requesterCanAccess($export)) {
                $export->update(['status' => 'failed', 'failure_message' => 'The requester no longer has access to this Analytics site.']);

                return null;
            }

            $export->update(['status' => 'processing', 'failure_message' => null]);

            return $export->fresh(['site']);
        }, attempts: 3);
        if (! $export) {
            return;
        }

        $path = null;
        try {
            $filters = $export->filters ?? [];
            $summary = $report->for($export->site, (int) ($filters['days'] ?? 30), $filters);
            $path = 'exports/'.$export->id.'.csv';
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

            if ($contents === false) {
                throw new RuntimeException('The report export could not be prepared.');
            }

            DB::connection('analytics')->transaction(function () use ($export, $path, $contents): void {
                DB::connection('analytics')->table('workspaces')->where('id', $export->workspace_id)->update(['id' => DB::raw('id')]);
                $workspace = Workspace::query()->whereKey($export->workspace_id)->lockForUpdate()->first();
                $current = ReportExport::query()->lockForUpdate()->with('site')->find($export->getKey());

                if ($workspace === null
                    || $current === null
                    || $current->status !== 'processing'
                    || app(AnalyticsDeletionFence::class)->isFenced('workspace', (string) $export->workspace_id)
                    || ! $this->requesterCanAccess($current)) {
                    $current?->update(['status' => 'failed', 'failure_message' => 'The Analytics workspace or requester is no longer available.']);

                    return;
                }

                if (! Storage::disk('analytics-local')->put($path, $contents)) {
                    throw new RuntimeException('The report export file could not be saved.');
                }

                $current->update(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()]);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('analytics-local')->delete($path);
            }
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
