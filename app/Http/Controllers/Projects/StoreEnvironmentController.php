<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateEnvironment;
use App\Enums\EnvironmentKind;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreEnvironmentController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, CreateEnvironment $create): RedirectResponse
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
}
