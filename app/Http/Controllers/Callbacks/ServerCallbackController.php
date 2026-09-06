<?php

namespace App\Http\Controllers\Callbacks;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Services\ServerProvisioningPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ServerCallbackController extends Controller
{
    /**
     * Record monotonic lifecycle progress from a signed callback.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function status(Request $request, Server $server): Response
    {
        $accepted = DB::transaction(function () use ($request, $server): bool {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->acceptsLifecycleCallback($request, $server)) {
                return false;
            }

            $finalStage = app(ServerProvisioningPlan::class)->finalStage($server);
            $data = $request->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
            $data['status'] = (int) $data['status'];
            if ($data['status'] > $server->setup_stage) {
                $server->update(['setup_stage' => $data['status']]);
            }

            if ($data['status'] === $finalStage) {
                $server->update([
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

        return $accepted ? response('') : response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(Request $request, Server $server): Response
    {
        DB::transaction(function () use ($request, $server): void {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->acceptsLifecycleCallback($request, $server)) {
                return;
            }

            $data = $request->validate([
                'exit_code' => 'nullable|integer',
                'message' => 'required|string|max:2000',
            ]);
            $server->update([
                'password' => null,
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => isset($data['exit_code'])
                    ? "{$data['message']} (exit code {$data['exit_code']})"
                    : $data['message'],
                'provisioning_failure_phase' => Server::FAILURE_REMOTE,
                'provisioning_process_id' => null,
                'provisioning_process_path' => null,
                'initialization_token' => null,
            ]);
            $server->logSnapshots()->updateOrCreate(
                ['type' => 'provisioning'],
                [
                    'status' => ServerLogSnapshot::STATUS_FAILED,
                    'error' => $server->provisioning_error,
                    'refreshed_at' => now(),
                ],
            );
        }, 5);

        return response()->noContent();
    }

    /**
     * Store bounded callback output without accepting stale attempts.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function log(Request $request, Server $server): Response
    {
        DB::transaction(function () use ($request, $server): void {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) $request->input('attempt'))) {
                return;
            }

            $data = $request->validate([
                'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.server_log_max_characters'))],
            ]);
            $locked->logSnapshots()->updateOrCreate(
                ['type' => 'provisioning'],
                [
                    'status' => ServerLogSnapshot::STATUS_READY,
                    'log' => $data['log'],
                    'error' => null,
                    'refreshed_at' => now(),
                ],
            );
        });

        return response()->noContent();
    }

    /**
     * Check current attempt identity and lifecycle state while the target row is locked.
     *
     * @param  Request  $request  The signed callback carrying the attempt token.
     * @param  Server  $server  The freshly locked record, never the stale route-binding snapshot.
     * @return bool Whether this callback can still change the lifecycle state.
     */
    private function acceptsLifecycleCallback(Request $request, Server $server): bool
    {
        return (! $server->provisioning_token || hash_equals($server->provisioning_token, (string) $request->input('attempt')))
            && in_array($server->provisioning_status, [
                Server::STATUS_QUEUED,
                Server::STATUS_WAITING_FOR_IP,
                Server::STATUS_PROVISIONING,
            ], true);
    }
}
