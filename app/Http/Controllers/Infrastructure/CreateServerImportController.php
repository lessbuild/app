<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Enums\ServerType;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class CreateServerImportController
{
    /**
     * The form for importing an existing server.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {

        return view('infrastructure.server-import', ['overview' => $overview->handle($project, $user), 'types' => ServerType::cases()]);
    }
}
