<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RecordBuildFailure;
use App\Actions\Deploy\RecordBuildLog;
use App\Actions\Deploy\RecordBuildRevision;
use App\Actions\Deploy\RecordBuildStage;
use App\Models\Build;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** What a deployment script reports (Deployer's signed `/builds/{build}/deployment/callback/{event}` URLs). */
final class RecordBuildCallbackController
{
    public function __invoke(Request $request, string $build, string $event): Response
    {
        $target = Build::query()->with(['website', 'repository'])->findOrFail((int) $build);
        match ($event) {
            'status' => app(RecordBuildStage::class)->handle($target, (int) $request->validate(['status' => ['required', 'integer', 'min:0', 'max:100']])['status']),
            'failed' => app(RecordBuildFailure::class)->handle($target, (string) $request->validate(['message' => ['required', 'string', 'max:2000']])['message'], $request->filled('exit_code') ? $request->integer('exit_code') : null),
            'revision' => app(RecordBuildRevision::class)->handle(
                $target,
                (string) $request->validate(['revision' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{40,64}\z/']])['revision'],
                $request->validate(['commit_message' => ['nullable', 'string', 'max:10000']])['commit_message'] ?? null,
            ),
            default => app(RecordBuildLog::class)->handle($target, (string) $request->validate(['log' => ['required', 'string', 'max:'.(max(1, (int) config('deploy.deployment_log_max_characters')) * 2)]])['log']),
        };

        return response()->noContent();
    }
}
