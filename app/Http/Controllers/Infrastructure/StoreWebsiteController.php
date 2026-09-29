<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreWebsiteController
{
    /**
     * Create a website and shows its database name and password once.
     *
     * @param  WebsiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CreateWebsite  $create
     * @return RedirectResponse
     */
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, Project $project, CreateWebsite $create): RedirectResponse
    {
        $website = $create->handle($project->account, $user, $request->validated());

        if (($guide = SetupGuideReturn::from($request)) !== null) {

            return redirect($guide)->with('status', __('Website created. It’s being set up on the server.'));

        }

        return to_route('infrastructure.websites.show', [$project, $website->id])->with('status', __('Website created. It’s being set up on the server.'))
            ->with('secrets', ['database' => $website->database_password, 'database_name' => $website->databaseIdentifier()]);
    }
}
