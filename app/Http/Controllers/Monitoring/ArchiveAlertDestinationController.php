<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveAlertDestinationController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $destination, AlertDestinationsQuery $destinations, ArchiveAlertDestination $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $archive->handle($project->account, $user, $destinations->find($project->account_id, $destination), $version);

        return to_route('monitoring.destinations', $project)->with('status', __(':destination was archived.', ['destination' => $target->name]));
    }
}
