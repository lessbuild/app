<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerLogs;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowServerController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $server, ProjectOverviewQuery $overview, ServersQuery $servers, ServerProvisioningPlan $plan): View
    {
        $record = $servers->find($project->account_id, $server);
        $logType = is_string($request->query('log')) && array_key_exists($request->query('log'), ServerLogs::TYPES) ? $request->query('log') : 'provisioning';

        return view('infrastructure.server', [
            'overview' => $overview->handle($project, $user),
            'server' => $record,
            'finalStage' => $plan->finalStage($record),
            'logType' => $logType,
            'logTypes' => array_keys(ServerLogs::TYPES),
            'log' => $record->logSnapshots()->where('type', $logType)->first(),
            'metrics' => $record->metrics()->where('recorded_at', '>=', CarbonImmutable::now('UTC')->subDay())->orderBy('recorded_at')->get(),
            'diagnostics' => $record->diagnosticSnapshot,
            'canManage' => $user->can('update', $project->account),
        ]);
    }
}
