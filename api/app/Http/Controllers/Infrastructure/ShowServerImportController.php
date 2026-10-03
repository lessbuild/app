<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\ServerImportAssessment;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowServerImportController
{
    /**
     * Show the import review page, for the person who ran the inspection.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $assessment
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $assessment, ProjectOverviewQuery $overview): View
    {
        $record = ServerImportAssessment::query()->where('account_id', $project->account_id)->where('user_id', $user->id)->findOrFail((int) $assessment);

        return view('infrastructure.server-import-review', [
            'overview' => $overview->handle($project, $user),
            'assessment' => $record,
            'usable' => $record->consumed_at === null && $record->expires_at->isFuture(),
        ]);
    }
}
