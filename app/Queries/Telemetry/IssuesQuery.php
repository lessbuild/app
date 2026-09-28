<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class IssuesQuery
{
    /**
     * The project's issues matching the issues page's status, severity and ownership filters and text search over title
     * and location.
     *
     * @param  Project  $project
     * @param  User  $user
     * @param  array<string, mixed>  $filters
     * @return Builder<Issue>
     */
    public function handle(Project $project, User $user, array $filters): Builder
    {
        $query = Issue::query()->whereBelongsTo($project);
        $query->when($filters['status'] !== 'all', fn (Builder $query): Builder => $query->where('status', $filters['status']));
        $query->when($filters['severity'] ?? null, fn (Builder $query, string $severity): Builder => $query->where('severity', $severity));

        if ($filters['ownership'] === 'mine') {
            $query->where('assignee_id', $user->id);
        } elseif ($filters['ownership'] === 'unassigned') {
            $query->whereNull('assignee_id');
        }

        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn (Builder $query): Builder => $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->whereRaw("location LIKE ? ESCAPE '!'", [$pattern], 'or'));
        }

        return $query;
    }
}
