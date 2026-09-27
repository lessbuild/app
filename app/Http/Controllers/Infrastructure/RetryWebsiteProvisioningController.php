<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryWebsiteProvisioning;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryWebsiteProvisioningController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $website, WebsitesQuery $websites, RetryWebsiteProvisioning $retry): RedirectResponse
    {
        $retried = $retry->handle($project->account, $user, $websites->find($project->account_id, $website));

        return to_route('infrastructure.websites.show', [$project, (int) $website])->with('status', $retried ? __('Trying again.') : __('Nothing is waiting for a retry.'));
    }
}
