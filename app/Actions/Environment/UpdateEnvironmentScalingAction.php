<?php

namespace App\Actions\Environment;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Models\Environment;
use App\Services\Entitlements;

class UpdateEnvironmentScalingAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Persist scaling settings, wake the environment, and queue the remote runtime transition.
     *
     * @param  array<string, mixed>  $attributes  Validated web or API scaling attributes.
     * @return Environment The updated environment.
     */
    public function handle(Environment $environment, array $attributes): Environment
    {
        $this->entitlements->enforce($environment->project->organization, 'scaling');
        $environment->update([...$attributes, 'hibernated_at' => null]);
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, false);

        return $environment;
    }
}
