<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Models\Build;
use App\Models\Incident;
use Carbon\CarbonImmutable;

final class AccountAttentionQuery
{
    /**
     * How many things to list at most.
     *
     * @var int
     */
    private const LIMIT = 4;

    /**
     * List what needs someone in these projects now, most urgent first: open incidents, then deploys whose latest
     * attempt in the last day failed.
     *
     * @param  list<string>  $projectIds  the projects the person can see
     * @return list<array{kind: string, icon: string, tone: string, title: string, text: string, url: string, action: string}>
     */
    public function handle(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return array_slice([...$this->incidents($projectIds), ...$this->failedDeploys($projectIds)], 0, self::LIMIT);
    }

    /**
     * Describe the open incidents, newest first.
     *
     * @param  list<string>  $projectIds
     * @return list<array{kind: string, icon: string, tone: string, title: string, text: string, url: string, action: string}>
     */
    private function incidents(array $projectIds): array
    {
        return array_values(Incident::query()->with('project')->whereIn('project_id', $projectIds)->whereNull('resolved_at')
            ->latest('opened_at')->limit(self::LIMIT)->get()
            ->filter(fn (Incident $incident): bool => $incident->project !== null)
            ->map(fn (Incident $incident): array => [
                'kind' => 'incident',
                'icon' => 'alert',
                'tone' => 'red',
                'title' => $this->text(':project has an open incident', ['project' => $incident->project->name ?? '']),
                'text' => $this->text(':title, open for :time.', ['title' => $incident->title, 'time' => $incident->opened_at->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE)]),
                'url' => route('monitoring.incidents.show', [$incident->project_id, $incident->id], false),
                'action' => $this->text('View incident'),
            ])->all());
    }

    /**
     * Describe the deploys whose latest attempt in the last day, per repository and environment, failed.
     *
     * @param  list<string>  $projectIds
     * @return list<array{kind: string, icon: string, tone: string, title: string, text: string, url: string, action: string}>
     */
    private function failedDeploys(array $projectIds): array
    {
        return array_values(Build::query()->with(['repository.project', 'environment'])
            ->whereHas('repository', fn ($query) => $query->whereIn('project_id', $projectIds))
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())
            ->latest('id')->get()
            ->unique(fn (Build $build): string => $build->repository_id.'-'.$build->environment_id)
            ->filter(fn (Build $build): bool => $build->status === Build::STATUS_FAILED)
            ->take(self::LIMIT)
            ->map(fn (Build $build): array => [
                'kind' => 'deploy',
                'icon' => 'rocket',
                'tone' => 'amber',
                'title' => $build->environment !== null
                    ? $this->text('A deploy of :project to :environment failed', ['project' => $build->repository->project->name, 'environment' => $build->environment->name])
                    : $this->text('A deploy of :project failed', ['project' => $build->repository->project->name]),
                'text' => $build->failure_message !== null && $build->failure_message !== '' ? str($build->failure_message)->limit(120)->toString() : $this->text('The last attempt didn’t finish.'),
                'url' => route('deploy.builds.show', [$build->repository->project_id, $build->id], false),
                'action' => $this->text('Open deploy'),
            ])->all());
    }

    /**
     * Translate a line, always as text.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @return string
     */
    private function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
