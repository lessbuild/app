<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Deploy\StartPipelineStep;
use App\Events\Deploy\DeployFinished;
use App\Models\Build;
use App\Models\DeployPipelineRun;
use App\Models\Repository;
use Illuminate\Events\Dispatcher;

final class DeployPipelineSubscriber
{
    /**
     * Create a new DeployPipelineSubscriber instance.
     *
     * @param  StartPipelineStep  $steps  Starts the next step.
     */
    public function __construct(private readonly StartPipelineStep $steps) {}

    /**
     * Register for finished deploys.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [DeployFinished::class => 'finished'];
    }

    /**
     * Move a pipeline run on when its current step's deploy finishes: deploy the next repository after a success, and
     * stop the run when the deploy failed, was cancelled or rejected, or it was the last step.
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function finished(DeployFinished $event): void
    {
        $build = $event->build;
        $run = DeployPipelineRun::query()->where('status', 'running')->with(['pipeline', 'starter'])->get()
            ->first(fn (DeployPipelineRun $run): bool => ($run->build_ids[array_key_last($run->build_ids) ?? 0] ?? null) === $build->id);
        if ($run === null) {
            return;
        }
        $repository = Repository::query()->find($build->repository_id);
        if ($build->status !== Build::STATUS_SUCCEEDED) {
            $run->forceFill(['status' => 'failed', 'failure' => mb_substr(__('Stopped: :repository’s deploy #:id :status.', ['repository' => $repository->name ?? '?', 'id' => $build->id, 'status' => $build->status]), 0, 500), 'finished_at' => now()])->save();

            return;
        }
        $next = $run->step + 1;
        $nextId = $run->pipeline->repository_ids[$next] ?? null;
        if ($nextId === null) {
            $run->forceFill(['status' => 'succeeded', 'step' => $next, 'finished_at' => now()])->save();

            return;
        }
        $nextRepository = Repository::query()->where('project_id', $run->pipeline->project_id)->find($nextId);
        $starter = $run->starter;
        if ($nextRepository === null || $starter === null) {
            $run->forceFill(['status' => 'failed', 'failure' => __('Stopped: the next repository or the person who started the run is gone.'), 'finished_at' => now()])->save();

            return;
        }
        $run->forceFill(['step' => $next])->save();
        $this->steps->handle($run, $starter, $nextRepository);
    }
}
