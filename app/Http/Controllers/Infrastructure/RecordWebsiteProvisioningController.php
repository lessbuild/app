<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RecordWebsiteProvisioning;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** What a website's setup script reports (Deployer's signed callback URLs). */
final class RecordWebsiteProvisioningController
{
    public function __invoke(Request $request, string $website, string $event, RecordWebsiteProvisioning $record): Response
    {
        $target = Website::query()->findOrFail((int) $website);
        $report = match ($event) {
            'status' => ['event' => 'status', 'stage' => (int) $request->validate(['status' => ['required', 'integer', 'min:0', 'max:100']])['status']],
            'failed' => (function () use ($request): array {
                $data = $request->validate(['exit_code' => ['nullable', 'integer'], 'message' => ['required', 'string', 'max:2000']]);

                return ['event' => 'failed', 'message' => (string) $data['message'], 'exit_code' => isset($data['exit_code']) ? (int) $data['exit_code'] : null];
            })(),
            default => ['event' => 'log', 'log' => (string) $request->validate(['log' => ['required', 'string', 'max:'.max(1, (int) config('infrastructure.server_log_max_characters'))]])['log']],
        };
        $record->handle($target, (string) $request->query('attempt', ''), $report);

        return response()->noContent();
    }
}
