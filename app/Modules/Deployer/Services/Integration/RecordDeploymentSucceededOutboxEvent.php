<?php

namespace App\Modules\Deployer\Services\Integration;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\DeploymentSucceededOutboxEvent;
use Illuminate\Support\Str;

final class RecordDeploymentSucceededOutboxEvent
{
    public function record(Build $build): ?DeploymentSucceededOutboxEvent
    {
        if ($build->status !== Build::STATUS_SUCCEEDED || $build->environment_id === null) {
            return null;
        }

        $environment = $build->environment()->first();

        if ($environment === null || $environment->project_id === null) {
            return null;
        }

        $revision = $this->revision($build->revision);
        $version = $revision ?? $this->releaseLabel($build->release_name) ?? 'build-'.$build->getKey();
        $deployedAt = $build->finished_at ?? $build->built_at ?? now();

        return DeploymentSucceededOutboxEvent::query()->firstOrCreate(
            [
                'event_type' => DeploymentSucceededOutboxEvent::EVENT_TYPE,
                'source_build_id' => $build->getKey(),
            ],
            [
                'event_version' => 1,
                'source_project_id' => $environment->project_id,
                'source_environment_id' => $environment->getKey(),
                'payload' => [
                    'deployment_id' => (string) Str::uuid(),
                    'version' => $version,
                    'revision' => $revision,
                    'deployed_at' => $deployedAt->copy()->utc()->toIso8601String(),
                ],
                'status' => 'pending',
                'available_at' => now(),
            ],
        );
    }

    private function revision(mixed $revision): ?string
    {
        if (! is_string($revision)) {
            return null;
        }

        $revision = strtolower(trim($revision));

        return preg_match('/\A[a-f0-9]{7,64}\z/D', $revision) === 1 ? $revision : null;
    }

    private function releaseLabel(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label !== '' && mb_strlen($label) <= 128 ? $label : null;
    }
}
