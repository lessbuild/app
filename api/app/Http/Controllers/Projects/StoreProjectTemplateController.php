<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\SaveProjectTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreProjectTemplateController
{
    /**
     * Save the project as a template and show it among the templates.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveProjectTemplate  $save
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SaveProjectTemplate $save): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500']]);
        $template = $save->handle($user, $project, $data['name'], $data['description'] ?? null);

        return to_route('projects.templates', ['template' => $template->key()])->with('status', __('Saved as the template “:name”. New projects can start from it here.', ['name' => $template->name]));
    }
}
