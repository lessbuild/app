<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use App\Services\Deploy\RepositoryDeploymentPlan;
use Illuminate\Support\Facades\DB;

final class RecordBuildStage
{
    public function __construct(private readonly RepositoryDeploymentPlan $plan, private readonly FinishBuild $finish) {}

    /** A stage of the deployment script finished (signed callback). The last one makes the build live. */
    public function handle(Build $build, int $stage): void
    {
        $final = DB::transaction(function () use ($build, $stage): bool {
            $locked = Build::query()->lockForUpdate()->find($build->id);
            if ($locked === null || ! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return false;
            }
            $locked->forceFill([
                'last_heartbeat_at' => now(), 'setup_stage' => max($locked->setup_stage, $stage),
                'activated_at' => $stage >= $this->plan->activationStage() ? ($locked->activated_at ?? now()) : $locked->activated_at,
            ])->save();

            return $stage >= $this->plan->finalStage();
        });
        if ($final) {
            $this->finish->handle($build, Build::STATUS_SUCCEEDED);
        }
    }
}
