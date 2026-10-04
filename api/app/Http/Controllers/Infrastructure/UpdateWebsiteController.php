<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\UpdateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateWebsiteController
{
    /**
     * Save a website, saying when the change means it's being set up again.
     *
     * @param  WebsiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  UpdateWebsite  $update
     * @return JsonResponse
     */
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, Project $project, Website $website, UpdateWebsite $update): JsonResponse
    {
        $record = $update->handle($project->account, $user, $website, $request->validated());

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $record->id], false), 'message' => $record->isProvisioning() ? __('Saved. The website is being set up again.') : __('Website saved.')]);
    }
}
