<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CloneEnvironment;
use App\Enums\EnvironmentKind;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CloneEnvironmentController
{
    /**
     * Create an environment from one of the project's environments and return to the project's settings, listing any
     * secrets left to set.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CloneEnvironment  $clone
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, CloneEnvironment $clone): JsonResponse
    {
        $data = $request->validateWithBag('cloneEnvironment', [
            'source_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(EnvironmentKind::class)->except([EnvironmentKind::Production])],
        ]);
        $source = Environment::query()->where('project_id', $project->id)->whereKey($data['source_id'])->firstOrFail();
        try {
            $result = $clone->handle($user, $source, $data['name'], EnvironmentKind::from($data['kind']), $request->boolean('copy_secrets'));
        } catch (ProjectRuleViolation $violation) {
            throw ValidationException::withMessages(['name' => $violation->getMessage()])->errorBag('cloneEnvironment');
        }
        $message = __(':environment created from :source.', ['environment' => $result['environment']->name, 'source' => $source->name]);
        if ($result['skipped_secrets'] !== []) {
            $message .= ' '.__('Set these secrets for it: :keys.', ['keys' => implode(', ', $result['skipped_secrets'])]);
        }

        return response()->json(['redirect' => route('projects.settings', $project, false), 'message' => $message]);
    }
}
