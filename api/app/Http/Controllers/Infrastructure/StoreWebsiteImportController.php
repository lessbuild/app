<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ImportWebsite;
use App\Http\Requests\Infrastructure\ImportWebsiteRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreWebsiteImportController
{
    /**
     * Adopt an existing website without changing anything on the server.
     *
     * @param  ImportWebsiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ImportWebsite  $import
     * @return JsonResponse
     */
    public function __invoke(ImportWebsiteRequest $request, #[CurrentUser] User $user, Project $project, ImportWebsite $import): JsonResponse
    {
        $website = $import->handle($project->account, $user, $request->website());

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id], false), 'message' => __('Website imported. Nothing on the server was changed.')]);
    }
}
