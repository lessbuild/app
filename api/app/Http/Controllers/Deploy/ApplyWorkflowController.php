<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApplyWorkflow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ApplyWorkflow $apply): RedirectResponse
    {
        $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $apply->handle($user, $project, $request->string('workflow')->toString());

        return to_route('deploy.configuration', $project)->with('status', __('Workflow applied.'));
    }
}
