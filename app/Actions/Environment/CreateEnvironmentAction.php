<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\EnvironmentRuntimeEntitlements;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEnvironmentAction
{
    public function __construct(
        private readonly EnvironmentRuntimeEntitlements $runtimeEntitlements,
        private readonly Gate $gate,
    ) {}

    /**
     * Create one environment after checking paid runtime features and project-scoped permissions.
     *
     * @param  array<string, mixed>  $attributes  Validated environment attributes.
     *
     * @throws ValidationException If the project already has a production environment.
     */
    public function handle(Project $project, User $actor, array $attributes): Environment
    {
        $this->runtimeEntitlements->enforce($project, $attributes);
        $this->gate->forUser($actor)->authorize('createEnvironment', [$project, $attributes]);
        if ($attributes['type'] === 'production' && $project->environments()->where('type', 'production')->exists()) {
            throw ValidationException::withMessages(['type' => __('This application already has a production environment.')]);
        }

        $base = Str::slug($attributes['name']) ?: 'environment';
        $slug = $base;
        $suffix = 2;
        while ($project->environments()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $project->environments()->create([...$attributes, 'slug' => $slug]);
    }
}
