<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\WebsiteProvisioner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowWebsiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $website, ProjectOverviewQuery $overview, WebsitesQuery $websites): View
    {
        $record = $websites->find($project->account_id, $website);

        return view('infrastructure.website', [
            'overview' => $overview->handle($project, $user),
            'website' => $record,
            'finalStage' => WebsiteProvisioner::finalStage(),
            'log' => $record->logs()->where('type', 'provisioning')->value('log'),
            'hosts' => $websites->hosts($project->account_id),
            'dnsProviders' => Provider::query()->where('account_id', $project->account_id)->where('type', ProviderType::Cloudflare)->orderBy('name')->get(),
            'temporaryDomains' => filled(config('infrastructure.temporary_base_domain')),
            'canManage' => $user->can('update', $project->account),
        ]);
    }
}
