<?php

namespace App\Actions\Server;

use App\Jobs\Server\RetryRemoteServerProvisioningJob;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Scripts\Server\ConfigureServerScript;
use App\Services\ServerProvisioningPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class QueueRemoteServerProvisioningRetryAction
{
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    public function handle(Server $server): bool
    {
        $rootPassword = null;
        $queued = DB::transaction(function () use ($server, &$rootPassword): bool {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);

            if ($locked->provisioning_status !== Server::STATUS_FAILED
                || $locked->provisioning_failure_phase !== Server::FAILURE_REMOTE) {
                return false;
            }

            if (! $locked->public_ip || ! $locked->ssh_private_key) {
                throw ValidationException::withMessages([
                    'retry' => __('The server must still be reachable by SSH before provisioning can be retried.'),
                ]);
            }

            if ($locked->setup_stage >= $this->plan->finalStage($locked)) {
                throw ValidationException::withMessages([
                    'retry' => __('All provisioning stages are already complete.'),
                ]);
            }

            $configureIndex = array_search(ConfigureServerScript::class, $this->plan->steps($locked), true);
            if ($configureIndex === false) {
                throw new LogicException('The server provisioning plan must include its configuration step.');
            }

            $configureStep = $configureIndex + 1;
            if ($locked->setup_stage < $configureStep) {
                $rootPassword = Str::random(40);
            }

            $token = (string) Str::uuid();
            $locked->update([
                'password' => $rootPassword,
                'provisioning_token' => $token,
                'provisioning_status' => Server::STATUS_QUEUED,
                'provisioning_error' => null,
                'provisioning_failure_phase' => null,
                'provisioned_at' => null,
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
            ]);
            $locked->logSnapshots()->updateOrCreate(
                ['type' => 'provisioning'],
                [
                    'status' => ServerLogSnapshot::STATUS_QUEUED,
                    'log' => null,
                    'error' => null,
                    'refreshed_at' => null,
                ],
            );

            RetryRemoteServerProvisioningJob::dispatch($locked->id, $token)->afterCommit();

            return true;
        });

        if ($queued && $rootPassword) {
            Session::flash('root_password', $rootPassword);
        }

        return $queued;
    }
}
