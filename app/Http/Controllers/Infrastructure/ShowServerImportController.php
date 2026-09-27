<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\ServerImportAssessment;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowServerImportController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $assessment, ProjectOverviewQuery $overview): View
    {
        Gate::authorize('update', $project->account);
        $record = ServerImportAssessment::query()->where('account_id', $project->account_id)->where('user_id', $user->id)->findOrFail((int) $assessment);

        return view('infrastructure.server-import-review', [
            'overview' => $overview->handle($project, $user),
            'assessment' => $record,
            'usable' => $record->consumed_at === null && $record->expires_at->isFuture(),
        ]);
    }
}
