<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Services\ProvisioningCallbackValidator;
use App\Services\ServerProvisioningCallbackGuard;
use Illuminate\Support\Facades\DB;

class RecordServerProvisioningFailureAction
{
    public function __construct(
        private readonly ProvisioningCallbackValidator $validator,
        private readonly ServerProvisioningCallbackGuard $guard,
    ) {}

    /**
     * Record a current server provisioning failure and its failed log snapshot.
     *
     * @param  Server  $server  Server receiving the signed failure callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $exitCode  Raw optional remote process exit code.
     * @param  mixed  $message  Raw remote failure message.
     * @return void Stale or terminal callbacks are acknowledged as no-ops.
     */
    public function handle(Server $server, mixed $attempt, mixed $exitCode, mixed $message): void
    {
        DB::transaction(function () use ($server, $attempt, $exitCode, $message): void {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->guard->acceptsLifecycle($locked, $attempt)) {
                return;
            }

            $failure = $this->validator->failure($exitCode, $message);
            $locked->update([
                'password' => null,
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => $failure->formattedMessage(),
                'provisioning_failure_phase' => Server::FAILURE_REMOTE,
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
                'initialization_token' => null,
            ]);
            $locked->logSnapshots()->updateOrCreate(
                ['type' => 'provisioning'],
                [
                    'status' => ServerLogSnapshot::STATUS_FAILED,
                    'error' => $locked->provisioning_error,
                    'refreshed_at' => now(),
                ],
            );
        }, 5);
    }
}
