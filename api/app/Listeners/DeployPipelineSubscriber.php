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
     * When one of a running pipeline's deploys finishes: a failure stops the run; a success, once every deploy in its
     * group is live, starts the next group's deploys together, or ends the run when there's none.
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function finished(DeployFinished $event): void
    {
        $build = $event->build;
        $run = DeployPipelineRun::query()->where('status', 'running')->with(['pipeline', 'starter'])->get()
            ->first(fn (DeployPipelineRun $run): bool => in_array($build->id, $this->groupBuilds($run), true));
        if ($run === null) {
            return;
        }
        $repository = Repository::query()->find($build->repository_id);
        if ($build->status !== Build::STATUS_SUCCEEDED) {
            $run->forceFill(['status' => 'failed', 'failure' => mb_substr(__('Stopped: :repository’s deploy #:id :status.', ['repository' => $repository->name ?? '?', 'id' => $build->id, 'status' => $build->status]), 0, 500), 'finished_at' => now()])->save();

            return;
        }
        $statuses = Build::query()->whereKey($this->groupBuilds($run))->pluck('status')->all();
        if (count($statuses) < count($run->pipeline->groups()[$run->step] ?? []) || array_any($statuses, fn (string $status): bool => $status !== Build::STATUS_SUCCEEDED)) {
            return;
        }
        $next = $run->step + 1;
        $group = $run->pipeline->groups()[$next] ?? null;
        if ($group === null) {
            $run->forceFill(['status' => 'succeeded', 'step' => $next, 'finished_at' => now()])->save();

            return;
        }
        $repositories = Repository::query()->where('project_id', $run->pipeline->project_id)->whereKey($group)->get();
        $starter = $run->starter;
        if ($repositories->count() !== count($group) || $starter === null) {
            $run->forceFill(['status' => 'failed', 'failure' => __('Stopped: the next repository or the person who started the run is gone.'), 'finished_at' => now()])->save();

            return;
        }
        $run->forceFill(['step' => $next])->save();
        foreach ($repositories->sortBy(fn (Repository $repository): int => (int) array_search($repository->id, $run->pipeline->repository_ids, true)) as $nextRepository) {
            if ($run->status === 'running') {
                $this->steps->handle($run, $starter, $nextRepository);
            }
        }
    }

    /**
     * The deploys of the run's current group: the last ones it started, one per step in the group.
     *
     * @param  DeployPipelineRun  $run
     * @return list<int>
     */
    private function groupBuilds(DeployPipelineRun $run): array
    {
        $size = count($run->pipeline->groups()[$run->step] ?? []);

        return $size === 0 ? [] : array_slice($run->build_ids, -$size);
    }
}
