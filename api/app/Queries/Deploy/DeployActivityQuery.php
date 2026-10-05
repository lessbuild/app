<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Models\Build;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DeployActivityQuery
{
    /**
     * How many recent deploys to list.
     *
     * @var int
     */
    private const RECENT = 50;

    /**
     * Describe a project's delivery lately: how often and how well it deploys (with the period before, for trends),
     * deploys waiting for approval, and the recent deploys across its repositories. Previews' deploys are left out.
     *
     * @param  Project  $project
     * @return array{stats: array<string, mixed>, waiting: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function handle(Project $project): array
    {
        $now = CarbonImmutable::now();

        return [
            'stats' => [
                'deploysThisWeek' => $this->builds($project)->where('created_at', '>=', $now->subDays(7))->count(),
                'deploysLastWeek' => $this->builds($project)->where('created_at', '>=', $now->subDays(14))->where('created_at', '<', $now->subDays(7))->count(),
                'daily' => $this->daily($project, $now),
                'successRate' => $this->successRate($project, $now->subDays(30)),
                'successRateBefore' => $this->successRate($project, $now->subDays(60), $now->subDays(30)),
                'medianSeconds' => $this->median($project, $now->subDays(30)),
                'medianSecondsBefore' => $this->median($project, $now->subDays(60), $now->subDays(30)),
                'rollbacks' => $this->builds($project)->where('trigger_source', 'rollback')->where('created_at', '>=', $now->subDays(30))->count(),
                'rollbacksBefore' => $this->builds($project)->where('trigger_source', 'rollback')->where('created_at', '>=', $now->subDays(60))->where('created_at', '<', $now->subDays(30))->count(),
            ],
            'waiting' => array_values($this->builds($project)->with('repository')->where('status', Build::STATUS_AWAITING_APPROVAL)->latest('id')->limit(5)->get()
                ->map(fn (Build $build): array => ['id' => $build->id, 'repository' => $build->repository->name, 'commitMessage' => $build->commit_message])->all()),
            'recent' => array_values($this->builds($project)->with(['repository', 'environment', 'requester'])->latest('id')->limit(self::RECENT)->get()
                ->map(fn (Build $build): array => [
                    'id' => $build->id,
                    'status' => $build->status,
                    'commitMessage' => $build->commit_message,
                    'revision' => $build->revision !== null ? substr($build->revision, 0, 7) : null,
                    'repository' => $build->repository->name,
                    'repositoryId' => $build->repository_id,
                    'environment' => $build->environment?->name,
                    'production' => $build->environment?->kind->value === 'production',
                    'trigger' => $build->trigger_source,
                    'requester' => $build->requester?->name,
                    'seconds' => $build->started_at !== null && $build->finished_at !== null ? (int) $build->started_at->diffInSeconds($build->finished_at, true) : null,
                    'createdAt' => $build->created_at?->toIso8601String(),
                ])->all()),
        ];
    }

    /**
     * The project's deploys, leaving out previews'.
     *
     * @param  Project  $project
     * @return Builder<Build>
     */
    private function builds(Project $project): Builder
    {
        return Build::query()->whereHas('repository', fn (Builder $query) => $query->where('project_id', $project->id)->whereDoesntHave('preview'));
    }

    /**
     * Count deploys on each of the last seven days, oldest first.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $now
     * @return list<int>
     */
    private function daily(Project $project, CarbonImmutable $now): array
    {
        $dates = $this->builds($project)->where('created_at', '>=', $now->startOfDay()->subDays(6))->pluck('created_at')
            ->map(fn ($at): string => CarbonImmutable::parse($at)->toDateString())->countBy();

        return array_map(fn (int $ago): int => (int) ($dates[$now->subDays($ago)->toDateString()] ?? 0), range(6, 0));
    }

    /**
     * The share of finished deploys in a period that went live, as a whole percentage, or null with none finished.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable|null  $until  the end of the period; open-ended for the current one
     * @return int|null
     */
    private function successRate(Project $project, CarbonImmutable $from, ?CarbonImmutable $until = null): ?int
    {
        $finished = $this->period($this->builds($project)->whereIn('status', [Build::STATUS_SUCCEEDED, Build::STATUS_FAILED]), $from, $until);
        $total = (clone $finished)->count();

        return $total === 0 ? null : (int) round((clone $finished)->where('status', Build::STATUS_SUCCEEDED)->count() / $total * 100);
    }

    /**
     * The median time a successful deploy took in a period, in seconds, or null with none.
     *
     * @param  Project  $project
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable|null  $until  the end of the period; open-ended for the current one
     * @return int|null
     */
    private function median(Project $project, CarbonImmutable $from, ?CarbonImmutable $until = null): ?int
    {
        $seconds = $this->period($this->builds($project)->where('status', Build::STATUS_SUCCEEDED)->whereNotNull('started_at')->whereNotNull('finished_at'), $from, $until)
            ->get(['started_at', 'finished_at'])
            ->map(fn (Build $build): int => (int) $build->started_at?->diffInSeconds($build->finished_at, true))->sort()->values();
        if ($seconds->isEmpty()) {
            return null;
        }
        $middle = intdiv($seconds->count(), 2);

        return $seconds->count() % 2 === 1 ? (int) $seconds[$middle] : (int) round(((int) $seconds[$middle - 1] + (int) $seconds[$middle]) / 2);
    }

    /**
     * Limit deploys to those created in a period.
     *
     * @param  Builder<Build>  $query
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable|null  $until  the end of the period; open-ended when null
     * @return Builder<Build>
     */
    private function period(Builder $query, CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        $query->where('created_at', '>=', $from);

        return $until === null ? $query : $query->where('created_at', '<', $until);
    }
}
