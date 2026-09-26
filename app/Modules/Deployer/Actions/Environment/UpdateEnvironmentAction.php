<?php

namespace App\Modules\Deployer\Actions\Environment;

use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Services\EnvironmentRuntimeEntitlements;
use Illuminate\Validation\ValidationException;

class UpdateEnvironmentAction
{
    public function __construct(private readonly EnvironmentRuntimeEntitlements $runtimeEntitlements) {}

    /**
     * Update environment runtime settings while preserving paid-feature checks and production uniqueness.
     *
     * @param  array<string, mixed>  $attributes  Validated environment attributes.
     *
     * @throws ValidationException If another production environment already exists.
     */
    public function handle(Environment $environment, array $attributes): Environment
    {
        $this->runtimeEntitlements->enforce($environment->project, $attributes, $environment);
        if ($attributes['type'] === 'production'
            && $environment->project->environments()->where('type', 'production')->whereKeyNot($environment->id)->exists()) {
            throw ValidationException::withMessages(['type' => __('This application already has a production environment.')]);
        }
        $environment->update($attributes);

        return $environment;
    }
}
