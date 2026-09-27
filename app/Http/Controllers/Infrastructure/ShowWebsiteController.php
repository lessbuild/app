<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Enums\ProviderType;
use App\Models\BackupDestination;
use App\Models\DatabaseClone;
use App\Models\Project;
use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\BackupsQuery;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\WebsiteProvisioner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowWebsiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, ProjectOverviewQuery $overview, WebsitesQuery $websites, BackupsQuery $backups): View
    {
        return view('infrastructure.website', [
            'overview' => $overview->handle($project, $user),
            'website' => $website,
            'finalStage' => WebsiteProvisioner::finalStage(),
            'log' => $website->logs()->where('type', 'provisioning')->value('log'),
            'hosts' => $websites->hosts($project->account_id),
            'environments' => $websites->environments($project->account),
            'dnsProviders' => Provider::query()->where('account_id', $project->account_id)->where('type', ProviderType::Cloudflare)->orderBy('name')->get(),
            'temporaryDomains' => filled(config('infrastructure.temporary_base_domain')),
            'canManage' => $user->can('update', $website),
            'backups' => $backups->recent($website->account_id, $website, 20),
            'schedules' => $website->backupSchedules()->with('destination')->get(),
            'backupDestinations' => BackupDestination::query()->where('account_id', $website->account_id)->orderBy('name')->get(),
            'canBackUp' => $user->can('backUp', $website),
            'canManageDatabase' => $user->can('manageDatabase', $website),
            'snapshot' => $website->databaseSnapshots()->latest('id')->first(),
            'databaseUsers' => $website->databaseUsers()->orderBy('username')->get(),
            'copyTargets' => Website::query()->where('account_id', $website->account_id)->where('server_id', $website->server_id)->whereKeyNot($website->id)->with('environment')->orderBy('name')->get(),
            'copies' => DatabaseClone::query()->with(['source', 'target'])->where(fn ($query) => $query->where('source_website_id', $website->id)->orWhere('target_website_id', $website->id))->latest('id')->limit(5)->get(),
        ]);
    }
}
