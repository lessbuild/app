<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RecordServerProvisioning;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What a provisioning script reports: `status` (a stage finished), `failed`, or `log`. The URLs are Deployer's, signed and
 * expiring, and carry the attempt token; stale or out-of-order reports are ignored but still answered 204.
 */
final class RecordServerProvisioningController
{
    /**
     * Record a provisioning script's report for the attempt named in the URL.
     *
     * @param  Request  $request
     * @param  string  $serverId
     * @param  string  $event
     * @param  RecordServerProvisioning  $record
     * @return Response
     */
    public function __invoke(Request $request, string $serverId, string $event, RecordServerProvisioning $record): Response
    {
        $target = Server::query()->findOrFail((int) $serverId);
        $attempt = (string) $request->query('attempt', '');
        $report = match ($event) {
            'status' => ['event' => 'status', 'stage' => (int) $request->validate(['status' => ['required', 'integer', 'min:0', 'max:1000']])['status']],
            'failed' => (function () use ($request): array {
                $data = $request->validate(['exit_code' => ['nullable', 'integer'], 'message' => ['required', 'string', 'max:2000']]);

                return ['event' => 'failed', 'message' => (string) $data['message'], 'exit_code' => isset($data['exit_code']) ? (int) $data['exit_code'] : null];
            })(),
            default => ['event' => 'log', 'log' => (string) $request->validate(['log' => ['required', 'string', 'max:'.max(1, (int) config('infrastructure.server_log_max_characters'))]])['log']],
        };
        $record->handle($target, $attempt, $report);

        return response()->noContent();
    }
}
