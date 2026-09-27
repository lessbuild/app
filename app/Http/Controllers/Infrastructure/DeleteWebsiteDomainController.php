<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteWebsiteDomain;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteWebsiteDomainController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $domain, DeleteWebsiteDomain $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $website->domains()->findOrFail((int) $domain));

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'domains'])->with('status', __('Domain removed.'));
    }
}
