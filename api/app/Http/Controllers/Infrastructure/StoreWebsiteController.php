<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreWebsiteController
{
    /**
     * Create a website and shows its database name and password once.
     *
     * @param  WebsiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CreateWebsite  $create
     * @return JsonResponse
     */
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, Project $project, CreateWebsite $create): JsonResponse
    {
        $website = $create->handle($project->account, $user, $request->validated());

        $message = __('Website created. It’s being set up on the server.');
        if (($guide = SetupGuideReturn::from($request)) !== null) {
            return response()->json(['redirect' => $guide, 'message' => $message]);
        }

        // The database password is shown once, on the website's page.
        return response()->json([
            'redirect' => route('infrastructure.websites.show', [$project, $website->id], false),
            'message' => $message,
            'secrets' => ['database' => $website->database_password, 'database_name' => $website->databaseIdentifier()],
        ]);
    }
}
