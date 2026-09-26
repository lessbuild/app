<?php

namespace App\Modules\Monitor\Console\Commands;

use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\DeliverUsageAlert;
use App\Modules\Monitor\Services\WorkspaceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('usage:send-alerts {--workspace= : Restrict alerts to a workspace ID} {--at= : Evaluate usage at a UTC timestamp}')]
#[Description('Send monthly event usage alerts to verified workspace owners')]
class SendUsageAlerts extends Command
{
    public function __construct(
        private readonly WorkspaceUsage $usage,
        private readonly DeliverUsageAlert $deliver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('monitor.beacon.usage_alerts.enabled', true)) {
            $this->info('Usage alerts are disabled.');

            return self::SUCCESS;
        }

        $workspaceId = $this->workspaceId();
        if ($workspaceId === false) {
            return 2;
        }

        $at = $this->timestamp();
        if ($at === false) {
            return 2;
        }
        $at ??= CarbonImmutable::now('UTC');

        $query = Workspace::query()->with('owner')->whereHas('owner', fn (Builder $owner): Builder => $owner->whereNotNull('email_verified_at'));
        if ($workspaceId !== null) {
            $query->whereKey($workspaceId);
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($query->orderBy('id')->lazyById(100) as $workspace) {
            $summary = $this->usage->summary($workspace, $at);
            foreach ($summary['crossed_thresholds'] as $threshold) {
                try {
                    match ($this->deliver->deliver($workspace, $workspace->owner, $summary, $threshold)) {
                        'sent' => $sent++,
                        'failed' => $failed++,
                        default => $skipped++,
                    };
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $this->error('Could not send the '.$threshold.'% usage alert for workspace #'.$workspace->id.'.');
                }
            }
        }

        $this->info(sprintf('Usage alerts: %d sent, %d skipped, %d failed.', $sent, $skipped, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function workspaceId(): int|false|null
    {
        $value = $this->option('workspace');
        if ($value === null) {
            return null;
        }

        $workspaceId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($workspaceId === false) {
            $this->error('The workspace must be a positive integer.');

            return false;
        }

        return $workspaceId;
    }

    private function timestamp(): CarbonImmutable|false|null
    {
        $value = $this->option('at');
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            $this->error('The at timestamp is invalid.');

            return false;
        }
    }
}
