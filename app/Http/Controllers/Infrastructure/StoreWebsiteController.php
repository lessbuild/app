<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreWebsiteController
{
    /**
     * Creates a website and shows its database name and password once.
     */
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, Project $project, CreateWebsite $create): RedirectResponse
    {
        $website = $create->handle($project->account, $user, $request->validated());

        return to_route('infrastructure.websites.show', [$project, $website->id])->with('status', __('Website created. It’s being set up on the server.'))
            ->with('secrets', ['database' => $website->database_password, 'database_name' => $website->databaseIdentifier()]);
    }
}
