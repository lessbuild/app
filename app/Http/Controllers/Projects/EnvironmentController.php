<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateEnvironment;
use App\Domain\Projects\Actions\DeleteEnvironment;
use App\Domain\Projects\Enums\EnvironmentKind;
use App\Domain\Projects\Exceptions\ProjectRuleViolation;
use App\Domain\Projects\Models\Project;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EnvironmentController
{
    public function store(Request $request, #[CurrentUser] User $user, Project $project, CreateEnvironment $create): RedirectResponse
    {
        $validated = $request->validateWithBag('environment', [
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(EnvironmentKind::class)->except([EnvironmentKind::Production])],
        ]);
        try {
            $environment = $create->handle($user, $project, $validated['name'], EnvironmentKind::from($validated['kind']));
        } catch (ProjectRuleViolation $violation) {
            // Keep it on the environment form rather than the project's own name field.
            throw ValidationException::withMessages([$violation->field => $violation->getMessage()])->errorBag('environment');
        }

        return to_route('projects.settings', $project)->with('status', __(':environment added.', ['environment' => $environment->name]));
    }

    public function destroy(#[CurrentUser] User $user, Project $project, string $environment, DeleteEnvironment $delete): RedirectResponse
    {
        $target = $project->environments()->findOrFail($environment);
        $delete->handle($user, $target);

        return to_route('projects.settings', $project)->with('status', __(':environment removed.', ['environment' => $target->name]));
    }
}
