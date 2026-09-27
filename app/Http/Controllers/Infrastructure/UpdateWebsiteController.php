<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\UpdateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateWebsiteController
{
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, Project $project, string $website, WebsitesQuery $websites, UpdateWebsite $update): RedirectResponse
    {
        $record = $update->handle($project->account, $user, $websites->find($project->account_id, $website), $request->validated());

        return to_route('infrastructure.websites.show', [$project, $record->id])->with('status', $record->isProvisioning() ? __('Saved. The website is being set up again.') : __('Website saved.'));
    }
}
