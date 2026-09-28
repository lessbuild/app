<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\RunServerProvisioning;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Services\Infrastructure\Scripts\Server\ConfigureServerScript;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RetryServerProvisioning
{
    /**
     * Starts a failed server's provisioning again from where it stopped.
     *
     * @param  ServerProvisioningPlan  $plan  The provisioning steps, to find where to resume.
     */
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    /**
     * Run the stages a failed remote provisioning didn't finish, over SSH. If it failed before the root password was set,
     * a new one is made and returned (shown once); otherwise null. Returns false if the server wasn't in that state.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Server  $server
     * @return string|false|null
     */
    public function handle(Account $account, User $actor, Server $server): string|false|null
    {
        Gate::forUser($actor)->authorize('update', $server);

        return DB::transaction(function () use ($account, $server): string|false|null {
            $locked = Server::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_status !== Server::STATUS_FAILED || $locked->provisioning_failure_phase !== Server::FAILURE_REMOTE) {
                return false;
            }
            if ($locked->public_ip === null || $locked->ssh_private_key === null) {
                throw ValidationException::withMessages(['retry' => __('The server must still be reachable over SSH to retry.')]);
            }
            if ($locked->setup_stage >= $this->plan->finalStage($locked)) {
                throw ValidationException::withMessages(['retry' => __('Every provisioning stage already finished.')]);
            }
            $configureStage = (int) array_search(ConfigureServerScript::class, $this->plan->steps($locked), true) + 1;
            $password = $locked->setup_stage < $configureStage ? Str::random(40) : null;
            $token = (string) Str::uuid();
            $locked->forceFill([
                'password' => $password, 'provisioning_token' => $token, 'initialization_token' => null,
                'provisioning_status' => Server::STATUS_QUEUED, 'provisioning_error' => null, 'provisioning_failure_phase' => null,
                'provisioned_at' => null, 'provisioning_process_id' => null, 'provisioning_process_path' => null,
            ])->save();
            $locked->logSnapshots()->updateOrCreate(['type' => 'provisioning'], ['status' => ServerLogSnapshot::STATUS_QUEUED, 'log' => null, 'error' => null, 'refreshed_at' => null]);
            RunServerProvisioning::dispatch($locked->id, $token)->afterCommit();

            return $password;
        });
    }
}
