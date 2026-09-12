<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\ProvisioningCallbackValidator;
use App\Services\ServerProvisioningCallbackGuard;
use App\Services\ServerProvisioningPlan;
use Illuminate\Support\Facades\DB;

class RecordServerProvisioningStatusAction
{
    public function __construct(
        private readonly ProvisioningCallbackValidator $validator,
        private readonly ServerProvisioningCallbackGuard $guard,
        private readonly ServerProvisioningPlan $plan,
    ) {}

    /**
     * Record a current server provisioning stage while retaining validation after the row lock.
     *
     * @param  Server  $server  Server receiving the signed status callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $status  Raw status validated after lifecycle acceptance.
     * @return bool Whether the callback was accepted for the current attempt.
     */
    public function handle(Server $server, mixed $attempt, mixed $status): bool
    {
        return DB::transaction(function () use ($server, $attempt, $status): bool {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->guard->acceptsLifecycle($locked, $attempt)) {
                return false;
            }

            $finalStage = $this->plan->finalStage($locked);
            $status = $this->validator->status($status, $finalStage);
            if ($status > $locked->setup_stage) {
                $locked->update(['setup_stage' => $status]);
            }

            if ($status === $finalStage) {
                $locked->update([
                    'provisioning_status' => Server::STATUS_ACTIVE,
                    'password' => null,
                    'provisioned_at' => now(),
                    'provisioning_error' => null,
                    'provisioning_failure_phase' => null,
                    'provisioning_process_id' => null,
                    'provisioning_process_path' => null,
                    'initialization_token' => null,
                ]);
            }

            return true;
        }, 5);
    }
}
