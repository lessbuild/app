<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAlertRule;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerLogs;
use App\Services\Infrastructure\ServerProvisioningPlan;
use App\Support\PageTabs;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowServerController
{
    /**
     * Show a server's page, in tabs: overview, alerts and diagnostics (once it's active), logs, and settings for
     * people who may change it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ProjectOverviewQuery  $overview
     * @param  ServerProvisioningPlan  $plan
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ProjectOverviewQuery $overview, ServerProvisioningPlan $plan): View
    {
        $logType = is_string($request->query('log')) && array_key_exists($request->query('log'), ServerLogs::TYPES) ? $request->query('log') : 'provisioning';

        $active = $server->provisioning_status === Server::STATUS_ACTIVE;
        $tabs = array_filter([
            'overview' => __('Overview'), 'alerts' => $active ? __('Alerts') : null, 'diagnostics' => $active ? __('Diagnostics') : null,
            'logs' => __('Logs'), 'settings' => $user->can('update', $server) ? __('Settings') : null,
        ]);

        return view('infrastructure.server', [
            'tabs' => $tabs,
            'tab' => PageTabs::current($request->query('tab', $request->has('log') ? 'logs' : null), $tabs),
            'overview' => $overview->handle($project, $user),
            'server' => $server,
            'finalStage' => $plan->finalStage($server),
            'logType' => $logType,
            'logTypes' => array_keys(ServerLogs::TYPES),
            'log' => $server->logSnapshots()->where('type', $logType)->first(),
            'metrics' => $server->metrics()->where('recorded_at', '>=', CarbonImmutable::now('UTC')->subDay())->orderBy('recorded_at')->get(),
            'diagnostics' => $server->diagnosticSnapshot,
            'alertRules' => ServerAlertRule::query()->where('account_id', $project->account_id)->where(fn ($query) => $query->whereNull('server_id')->orWhere('server_id', $server->id))->orderBy('name')->get(),
            'canManage' => $user->can('update', $server),
            'canOpenTerminal' => $user->can('openTerminal', $server),
        ]);
    }
}
