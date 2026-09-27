<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SendTestAlert;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SendTestAlertController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $destination, AlertDestinationsQuery $destinations, SendTestAlert $send): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $destinations->find($project->account_id, $destination);
        $send->handle($project->account, $user, $target, $version);

        return to_route('monitoring.destinations.show', [$project, $target->id])->with('status', __('Test notification queued. Its outcome appears below.'));
    }
}
