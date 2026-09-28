<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Jobs\Infrastructure\RemoveWebsitePlacement;
use App\Models\Website;
use App\Services\Infrastructure\WebsiteProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordWebsiteProvisioning
{
    /**
     * Record what a website's setup script reported. Stale attempts are ignored. When the last stage finishes the website
     * goes live, its domains are applied, and a copy left on a previous server is removed.
     *
     * @param  Website  $website
     * @param  string  $attempt
     * @param  array{event: 'status', stage: int}|array{event: 'failed', message: string, exit_code: int|null}|array{event: 'log', log: string}  $report
     * @return bool
     */
    public function handle(Website $website, string $attempt, array $report): bool
    {
        return DB::transaction(function () use ($website, $attempt, $report): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->provisioning_token !== null && ! hash_equals($locked->provisioning_token, $attempt)) {
                return false;
            }
            if ($report['event'] === 'log') {
                $locked->logs()->updateOrCreate(['type' => 'provisioning'], ['log' => $report['log']]);

                return true;
            }
            if (! $locked->isProvisioning()) {
                return false;
            }
            if ($report['event'] === 'failed') {
                $locked->forceFill(['provisioning_status' => Website::STATUS_FAILED, 'provisioning_error' => Str::limit($report['message'].($report['exit_code'] !== null ? " (exit code {$report['exit_code']})" : ''), 2000)])->save();

                return true;
            }
            if ($report['stage'] > WebsiteProvisioner::finalStage()) {
                return false;
            }
            $locked->setup_stage = max($locked->setup_stage, $report['stage']);
            if ($report['stage'] === WebsiteProvisioner::finalStage()) {
                $locked->forceFill(['provisioning_status' => Website::STATUS_ACTIVE, 'provisioned_at' => CarbonImmutable::now('UTC'), 'provisioning_error' => null]);
                if ($locked->previous_server_id !== null) {
                    RemoveWebsitePlacement::dispatch($locked->id, $locked->previous_server_id, $locked->deployment_slug)->afterCommit();
                }
                if ($locked->domains()->where('type', '!=', 'primary')->exists()) {
                    ApplyWebsiteDomains::dispatch($locked->id)->afterCommit();
                }
            }
            $locked->save();

            return true;
        }, attempts: 5);
    }
}
