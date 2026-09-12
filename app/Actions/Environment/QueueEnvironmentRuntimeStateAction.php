<?php

namespace App\Actions\Environment;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Models\Environment;
use App\Services\Entitlements;

class QueueEnvironmentRuntimeStateAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Queue a validated running or hibernated state transition for an environment.
     *
     * @param  string  $state  Persisted API/web state value, either running or hibernated.
     */
    public function handle(Environment $environment, string $state): void
    {
        $hibernate = $state === 'hibernated';
        if ($hibernate) {
            $this->entitlements->enforce($environment->project->organization, 'hibernation');
        }

        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, $hibernate);
    }
}
