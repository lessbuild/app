<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApplyWorkflow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplyWorkflowController
{
    /**
     * Apply a version 1 workflow document from the Configuration page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ApplyWorkflow  $apply
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ApplyWorkflow $apply): JsonResponse
    {
        $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $apply->handle($user, $project, $request->string('workflow')->toString());

        return response()->json(['redirect' => route('deploy.configuration', $project, false), 'message' => __('Workflow applied.')]);
    }
}
