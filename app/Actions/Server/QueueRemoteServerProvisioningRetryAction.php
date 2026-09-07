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
    /**
     * Use the provisioning plan to locate unfinished remote setup stages.
     *
     * @param  ServerProvisioningPlan  $plan  Ordered provisioning or deployment plan defining the steps to render.
     */
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    /**
     * Validate a failed remote provisioning attempt, rotate its token and required credentials, reset its state, and queue the remaining setup after commit.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @return bool Whether a failed remote attempt was claimed and queued; false when the failure is not eligible.
     *
     * @throws ValidationException If the server lacks the address or credentials required for a retry.
     * @throws LogicException If the provisioning plan lacks its required configuration step.
     */
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
                'initialization_token' => null,
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
