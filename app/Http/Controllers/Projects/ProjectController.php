<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\DeleteProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Enums\EnvironmentKind;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\ProjectOverviewQuery;
use App\Http\Requests\Projects\ProjectRequest;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProjectController
{
    public function create(#[CurrentUser] User $user): View
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('create', [Project::class, $account]);

        return view('projects.create', ['account' => $account]);
    }

    public function store(ProjectRequest $request, #[CurrentUser] User $user, CreateProject $create): RedirectResponse
    {
        $project = $create->handle($user, $user->currentAccount ?? abort(404), $request->toDetails());

        return to_route('projects.show', $project)->with('status', __('Project created. Next, turn on the services you need.'));
    }

    public function show(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query): View
    {
        return view('projects.show', ['overview' => $query->handle($project, $user)]);
    }

    public function edit(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $query): View
    {
        Gate::authorize('update', $project);

        return view('projects.settings', [
            'overview' => $query->handle($project, $user),
            'kinds' => array_values(array_filter(EnvironmentKind::cases(), fn (EnvironmentKind $kind): bool => $kind !== EnvironmentKind::Production)),
        ]);
    }

    public function update(ProjectRequest $request, #[CurrentUser] User $user, Project $project, UpdateProject $update): RedirectResponse
    {
        $update->handle($user, $project, $request->toDetails());

        return to_route('projects.settings', $project)->with('status', __('Project saved.'));
    }

    public function destroy(Request $request, #[CurrentUser] User $user, Project $project, DeleteProject $delete): RedirectResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim($request->string('confirm_name')->toString()) !== $project->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the project name exactly as shown to confirm.')])->errorBag('deleteProject');
        }

        $delete->handle($user, $project);

        return to_route('dashboard')->with('status', __(':project was deleted.', ['project' => $project->name]));
    }
}
