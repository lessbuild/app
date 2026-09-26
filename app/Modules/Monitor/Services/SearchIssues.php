<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class SearchIssues
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Issue>
     */
    public function query(Workspace $workspace, User $user, array $filters): Builder
    {
        $query = Issue::forWorkspace($workspace)->visibleTo($user, $workspace);
        $query->when($filters['status'] !== 'all', fn (Builder $query): Builder => $query->where('status', $filters['status']));
        $query->when($filters['application'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('application_id', $id));
        $query->when($filters['severity'] ?? null, fn (Builder $query, string $severity): Builder => $query->where('severity', $severity));

        if ($filters['ownership'] === 'mine') {
            $query->where('assignee_id', $user->id);
        } elseif ($filters['ownership'] === 'unassigned') {
            $query->whereNull('assignee_id');
        }

        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn (Builder $query): Builder => $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("location LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query;
    }
}
