<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerLogSnapshot;
use App\Modules\Deployer\Services\ProvisioningCallbackValidator;
use App\Modules\Deployer\Services\ServerProvisioningCallbackGuard;
use Illuminate\Support\Facades\DB;

class RecordServerProvisioningLogAction
{
    public function __construct(
        private readonly ProvisioningCallbackValidator $validator,
        private readonly ServerProvisioningCallbackGuard $guard,
    ) {}

    /**
     * Record bounded server provisioning output for the current attempt.
     *
     * Validation deliberately occurs after the row lock and attempt check;
     * tokenless legacy callbacks and stale callbacks retain their old behavior.
     *
     * @param  Server  $server  Server receiving the signed log callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $log  Raw log input validated only after attempt acceptance.
     * @return void Stale callbacks are acknowledged as no-ops.
     */
    public function handle(Server $server, mixed $attempt, mixed $log): void
    {
        DB::connection('deployer')->transaction(function () use ($server, $attempt, $log): void {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->guard->matchesAttempt($locked, $attempt)) {
                return;
            }

            $validatedLog = $this->validator->log(
                $log,
                (int) config('lessbuild.server_log_max_characters'),
            );
            $locked->logSnapshots()->updateOrCreate(
                ['type' => 'provisioning'],
                [
                    'status' => ServerLogSnapshot::STATUS_READY,
                    'log' => $validatedLog,
                    'error' => null,
                    'refreshed_at' => now(),
                ],
            );
        });
    }
}
