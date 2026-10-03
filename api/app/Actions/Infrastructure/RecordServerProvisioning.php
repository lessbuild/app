<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordServerProvisioning
{
    /**
     * Create a new RecordServerProvisioning instance.
     *
     * Records progress reported by a server's provisioning script.
     *
     * @param  ServerProvisioningPlan  $plan  Knows which stage is last.
     */
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    /**
     * Record what a provisioning script reported: a finished stage, a failure, or its log. Reports from an older attempt
     * (a different token), and stage or failure reports once the server is no longer provisioning, are ignored.
     *
     * @param  Server  $server
     * @param  string  $attempt
     * @param  array{event: 'status', stage: int}|array{event: 'failed', message: string, exit_code: int|null}|array{event: 'log', log: string}  $report
     * @return bool
     */
    public function handle(Server $server, string $attempt, array $report): bool
    {
        return DB::transaction(function () use ($server, $attempt, $report): bool {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_token !== null && ! hash_equals($locked->provisioning_token, $attempt)) {
                return false;
            }
            if ($report['event'] === 'log') {
                $locked->logSnapshots()->updateOrCreate(['type' => 'provisioning'], ['status' => ServerLogSnapshot::STATUS_READY, 'log' => $report['log'], 'error' => null, 'refreshed_at' => CarbonImmutable::now('UTC')]);

                return true;
            }
            if (! $locked->isProvisioning()) {
                return false;
            }
            if ($report['event'] === 'failed') {
                $message = Str::limit($report['message'].($report['exit_code'] !== null ? " (exit code {$report['exit_code']})" : ''), 2000);
                $locked->forceFill([
                    'password' => null, 'provisioning_status' => Server::STATUS_FAILED, 'provisioning_error' => $message,
                    'provisioning_failure_phase' => Server::FAILURE_REMOTE, 'provisioning_process_id' => null, 'provisioning_process_path' => null, 'initialization_token' => null,
                ])->save();
                $locked->logSnapshots()->updateOrCreate(['type' => 'provisioning'], ['status' => ServerLogSnapshot::STATUS_FAILED, 'error' => $message, 'refreshed_at' => CarbonImmutable::now('UTC')]);

                return true;
            }
            $final = $this->plan->finalStage($locked);
            if ($report['stage'] > $final) {
                return false;
            }
            if ($report['stage'] > $locked->setup_stage) {
                $locked->setup_stage = $report['stage'];
            }
            if ($report['stage'] === $final) {
                $locked->forceFill([
                    'provisioning_status' => Server::STATUS_ACTIVE, 'password' => null, 'provisioned_at' => CarbonImmutable::now('UTC'),
                    'provisioning_error' => null, 'provisioning_failure_phase' => null, 'provisioning_process_id' => null, 'provisioning_process_path' => null, 'initialization_token' => null,
                ]);
            }
            $locked->save();

            return true;
        }, attempts: 5);
    }
}
