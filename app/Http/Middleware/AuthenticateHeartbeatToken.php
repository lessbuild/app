<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorQueue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** Checks a heartbeat monitor's own bearer key (public contract from the old Monitor app), then applies its per-monitor limit. */
final class AuthenticateHeartbeatToken
{
    /**
     * Authenticates heartbeat pings.
     *
     * @param  MonitorQueue  $queue  Checks the monitor still accepts pings.
     * @param  ThrottleRequests  $throttle  Applies the per-monitor rate limit once the key is known.
     */
    public function __construct(private readonly MonitorQueue $queue, private readonly ThrottleRequests $throttle) {}

    /**
     * Finds the heartbeat monitor in the URL and checks the bearer key against its hash, then passes the monitor and key
     * hash on as request attributes. Wrong keys and unavailable monitors get the same 401.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        abort_unless(is_string($secret) && strlen($secret) >= 16 && strlen($secret) <= 256,
            401, 'A monitor-specific heartbeat bearer key is required.');
        $monitor = Monitor::query()->where('type', 'heartbeat')->with('environment.project')->whereKey((int) $request->route('heartbeat', '0'))->first();
        $hash = hash('sha256', $secret);
        abort_unless($this->queue->eligible($monitor) && is_string($monitor->heartbeat_token_hash)
            && hash_equals($monitor->heartbeat_token_hash, $hash), 401, 'The heartbeat key is invalid or the source is unavailable.');
        $request->attributes->set('heartbeat_monitor_id', $monitor->id);
        $request->attributes->set('heartbeat_token_hash', $hash);

        return $this->throttle->handle($request, $next, 'heartbeats');
    }
}
