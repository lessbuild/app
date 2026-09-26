<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\RotateHeartbeatTokenRequest;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\RotateHeartbeatToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;

class HeartbeatTokenController extends Controller
{
    public function store(RotateHeartbeatTokenRequest $request, Monitor $monitor, CurrentWorkspace $workspace, RotateHeartbeatToken $tokens): RedirectResponse
    {
        $secret = $tokens->change($workspace->get(), $request->user(), $monitor, (int) $request->validated('version'));

        return to_route('monitor.monitors.show', $monitor)
            ->with('status', 'Heartbeat key issued. The previous key no longer works. Copy this key now; only its hash is stored.')
            ->with('issued_heartbeat_key', ['monitor_id' => $monitor->id, 'token_hash' => hash('sha256', $secret),
                'encrypted_secret' => Crypt::encryptString($secret)]);
    }

    public function destroy(RotateHeartbeatTokenRequest $request, Monitor $monitor, CurrentWorkspace $workspace, RotateHeartbeatToken $tokens): RedirectResponse
    {
        $tokens->change($workspace->get(), $request->user(), $monitor, (int) $request->validated('version'), revoke: true);
        $request->session()->forget('issued_heartbeat_key');

        return to_route('monitor.monitors.show', $monitor)->with('status', 'Heartbeat key revoked and monitor paused. Active incidents are retained.');
    }
}
