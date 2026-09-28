<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertDestination;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveAlertDestinationController
{
    /**
     * Archives a destination, if it hasn't changed since the page was opened.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDestination  $destination
     * @param  ArchiveAlertDestination  $archive
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDestination $destination, ArchiveAlertDestination $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $archive->handle($project->account, $user, $destination, $version);

        return to_route('monitoring.destinations', $project)->with('status', __(':destination was archived.', ['destination' => $target->name]));
    }
}
