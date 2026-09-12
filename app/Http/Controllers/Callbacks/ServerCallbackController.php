<?php

namespace App\Http\Controllers\Callbacks;

use App\Actions\Server\RecordServerProvisioningFailureAction;
use App\Actions\Server\RecordServerProvisioningLogAction;
use App\Actions\Server\RecordServerProvisioningStatusAction;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServerCallbackController extends Controller
{
    /**
     * Record monotonic lifecycle progress from a signed callback.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function status(
        Request $request,
        Server $server,
        RecordServerProvisioningStatusAction $record,
    ): Response {
        $accepted = $record->handle(
            $server,
            $request->input('attempt'),
            $request->input('status'),
        );

        return $accepted ? response('') : response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  Request  $request  Signed callback input, validated after lifecycle acceptance.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(
        Request $request,
        Server $server,
        RecordServerProvisioningFailureAction $record,
    ): Response {
        $record->handle(
            $server,
            $request->input('attempt'),
            $request->input('exit_code'),
            $request->input('message'),
        );

        return response()->noContent();
    }

    /**
     * Store bounded callback output without accepting stale attempts.
     *
     * @param  Request  $request  Signed callback input, validated after lifecycle acceptance.
     * @param  Server  $server  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function log(
        Request $request,
        Server $server,
        RecordServerProvisioningLogAction $record,
    ): Response {
        $record->handle(
            $server,
            $request->input('attempt'),
            $request->input('log'),
        );

        return response()->noContent();
    }
}
