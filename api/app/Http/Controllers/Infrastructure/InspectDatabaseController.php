<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RequestDatabaseInspection;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class InspectDatabaseController
{
    /**
     * Start a database inspection, unless one is already running.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  RequestDatabaseInspection  $inspect
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, RequestDatabaseInspection $inspect): JsonResponse
    {
        $snapshot = $inspect->handle($website, $user);

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'], false), 'message' => $snapshot === null ? __('An inspection is already running.') : __('Inspecting the database.')]);
    }
}
