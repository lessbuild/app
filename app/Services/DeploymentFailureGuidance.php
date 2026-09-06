<?php

namespace App\Services;

use App\Models\Build;

class DeploymentFailureGuidance
{
    /** @return array{title: string, summary: string, failed_step: ?string, last_completed: ?string} */
    public function for(Build $build, RepositoryDeploymentPlan $plan): array
    {
        $scripts = $plan->scripts();
        $completed = max(0, min($build->setup_stage, count($scripts)));
        $failed = $scripts[$completed] ?? null;
        $last = $completed > 0 ? $scripts[$completed - 1] : null;

        [$title, $summary] = match (true) {
            $build->trigger_source === Build::TRIGGER_ROLLBACK => [
                'Release restore failed',
                'Confirm the retained release still exists on the target server and inspect the rollback log before trying another retained artifact.',
            ],
            $completed < 2 => [
                'Check source access',
                'Confirm the repository URL, deployment branch, provider credential, and deploy key access before retrying.',
            ],
            $completed < 5 => [
                'Check dependencies and runtime',
                'Review the lockfile, selected runtime version, package registry access, and the first error in the deployment log.',
            ],
            $completed < 8 => [
                'Check application build commands',
                'Run the failing build or framework command locally with production settings, then correct the command or environment variables.',
            ],
            $completed < 13 => [
                'Check release configuration',
                'Review web runtime, managed resources, process definitions, and post-deployment commands. The previous release remains available.',
            ],
            $completed < 14 => [
                'Health verification failed',
                'Inspect the application and web-server logs, confirm the health path returns a successful response, then retry or restore a retained release.',
            ],
            default => [
                'Review the deployment log',
                'Use the final error and downloadable log to correct the application, then redeploy the exact revision.',
            ],
        };

        return [
            'title' => $title,
            'summary' => $summary,
            'failed_step' => $failed !== null ? $failed::$title : null,
            'last_completed' => $last !== null ? $last::$title : null,
        ];
    }
}
