<?php

namespace App\Jobs\Web;

use App\Models\Website;
use App\Models\WebsiteLogSnapshot;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RefreshWebsiteLogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60;

    /**
     * Capture the website and runtime log category whose queued snapshot should be refreshed.
     *
     * @param  int  $websiteId  Website identifier retained for lookup when the job runs.
     * @param  string  $type  Supported log category identifying the snapshot and remote log source.
     */
    public function __construct(public int $websiteId, public string $type) {}

    /**
     * Coalesce refresh requests for the same website and runtime log category.
     *
     * @return string The website identifier and log category used as the unique-job lock key.
     */
    public function uniqueId(): string
    {
        return "{$this->websiteId}:{$this->type}";
    }

    /**
     * Claim an existing queued runtime-log snapshot, fetch its configured trailing lines, and record readiness; skip missing websites, unsupported categories, or changed snapshot state.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $website = Website::query()->with('server')->find($this->websiteId);
        if (! $website || ! in_array($this->type, WebsiteLogSnapshot::TYPES, true)) {
            return;
        }
        $snapshot = $website->runtimeLogs()->where('type', $this->type)->first();
        if (! $snapshot || $snapshot->status !== WebsiteLogSnapshot::STATUS_QUEUED) {
            return;
        }
        $snapshot->update(['status' => WebsiteLogSnapshot::STATUS_REFRESHING, 'error' => null]);
        $path = match ($this->type) {
            'application' => "/var/www/{$website->deployment_slug}/current/storage/logs/laravel.log",
            'access' => "/var/log/caddy/{$website->deployment_slug}.access.log",
        };
        $lines = min(10000, max(100, $website->log_retention_lines ?: 1000));
        $result = $runner->server($website->server)->create()->execute('tail -n '.escapeshellarg((string) $lines).' -- '.escapeshellarg($path).' 2>/dev/null || true');
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Unable to refresh the website log.');
        }
        $snapshot->update([
            'status' => WebsiteLogSnapshot::STATUS_READY,
            'log' => $result->getOutput(),
            'error' => null,
            'refreshed_at' => now(),
        ]);
    }

    /**
     * Mark the requested runtime-log snapshot failed with a bounded queue error.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        WebsiteLogSnapshot::query()
            ->where('website_id', $this->websiteId)
            ->where('type', $this->type)
            ->update([
                'status' => WebsiteLogSnapshot::STATUS_FAILED,
                'error' => str($exception->getMessage())->limit(1000),
            ]);
    }
}
