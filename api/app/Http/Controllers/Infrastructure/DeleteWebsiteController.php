<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteWebsite;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteWebsiteController
{
    /**
     * Delete a website; it's removed from its server in the background.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  DeleteWebsite  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, DeleteWebsite $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $website);

        return response()->json(['redirect' => route('infrastructure.websites', $project, false), 'message' => __('Website deleted. It’s being removed from its server.')]);
    }
}
