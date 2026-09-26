<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\RotateQueueTokenRequest;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\RotateQueueToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;

class QueueTokenController extends Controller
{
    public function store(RotateQueueTokenRequest $request, Monitor $monitor, CurrentWorkspace $workspace, RotateQueueToken $tokens): RedirectResponse
    {
        $secret = $tokens->change($workspace->get(), $request->user(), $monitor, (int) $request->validated('version'));

        return to_route('monitor.monitors.show', $monitor)
            ->with('status', 'Queue key issued. Copy it now; the previous key no longer works and only a hash is stored.')
            ->with('issued_queue_key', ['monitor_id' => $monitor->id, 'token_hash' => hash('sha256', $secret),
                'encrypted_secret' => Crypt::encryptString($secret)]);
    }

    public function destroy(RotateQueueTokenRequest $request, Monitor $monitor, CurrentWorkspace $workspace, RotateQueueToken $tokens): RedirectResponse
    {
        $tokens->change($workspace->get(), $request->user(), $monitor, (int) $request->validated('version'), revoke: true);
        $request->session()->forget('issued_queue_key');

        return to_route('monitor.monitors.show', $monitor)->with('status', 'Queue key revoked and monitor paused. Active incidents and history are retained.');
    }
}
