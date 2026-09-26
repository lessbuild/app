<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use App\Modules\Monitor\Services\MonitorQueue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQueueToken
{
    public function __construct(private readonly MonitorQueue $queue, private readonly ThrottleRequests $throttle) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        abort_unless(is_string($secret) && strlen($secret) >= 16 && strlen($secret) <= 256,
            401, 'A monitor-specific queue bearer key is required.');
        $monitor = Monitor::query()->where('type', 'queue')->with('environment.application')->find($request->route('queue'));
        $hash = hash('sha256', $secret);
        abort_unless($this->queue->eligible($monitor) && is_string($monitor->queue_token_hash)
            && hash_equals($monitor->queue_token_hash, $hash), 401, 'The queue key is invalid or the source is unavailable.');
        MonitorDeletionFence::assertWorkspaceActive($monitor->environment->application->workspace_id);
        $request->attributes->set('queue_monitor_id', $monitor->id);
        $request->attributes->set('queue_token_hash', $hash);

        return $this->throttle->handle($request, $next, 'queue-signals');
    }
}
