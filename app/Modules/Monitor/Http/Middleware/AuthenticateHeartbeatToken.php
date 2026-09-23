<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\MonitorQueue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHeartbeatToken
{
    public function __construct(private readonly MonitorQueue $queue, private readonly ThrottleRequests $throttle) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        abort_unless(is_string($secret) && strlen($secret) >= 16 && strlen($secret) <= 256,
            401, 'A monitor-specific heartbeat bearer key is required.');
        $monitor = Monitor::query()->where('type', 'heartbeat')->with('environment.application')->find($request->route('heartbeat'));
        $hash = hash('sha256', $secret);
        abort_unless($this->queue->eligible($monitor) && is_string($monitor->heartbeat_token_hash)
            && hash_equals($monitor->heartbeat_token_hash, $hash), 401, 'The heartbeat key is invalid or the source is unavailable.');
        $request->attributes->set('heartbeat_monitor_id', $monitor->id);
        $request->attributes->set('heartbeat_token_hash', $hash);

        return $this->throttle->handle($request, $next, 'heartbeats');
    }
}
