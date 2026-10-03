<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Illuminate\Http\JsonResponse;

final class ShowServerStatusController
{
    /**
     * Report a server's setup progress as JSON, so its page can follow provisioning live without reloading.
     *
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerProvisioningPlan  $plan
     * @return JsonResponse
     */
    public function __invoke(Project $project, Server $server, ServerProvisioningPlan $plan): JsonResponse
    {
        $status = $server->provisioning_status;

        return response()->json([
            'status' => $status,
            'badge' => view('infrastructure._status', ['server' => $server])->render(),
            'stage' => $server->setup_stage,
            'final_stage' => $plan->finalStage($server),
            'step' => $status === Server::STATUS_PROVISIONING ? $plan->currentStep($server) : null,
            'reason' => $status === Server::STATUS_WAITING_FOR_IP ? $server->provisioning_error : null,
            'public_ip' => $server->public_ip,
            'ssh' => 'root@'.($server->public_ip ?? '—').':'.$server->ssh_port,
            'host_key' => $server->ssh_host_fingerprint,
            'finished' => ! $server->isProvisioning(),
        ])->header('Cache-Control', 'no-store');
    }
}
