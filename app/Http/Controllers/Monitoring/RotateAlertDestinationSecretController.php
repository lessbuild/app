<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RotateAlertDestinationSecret;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RotateAlertDestinationSecretController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDestination $destination, RotateAlertDestinationSecret $rotate): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $rotate->handle($project->account, $user, $destination, $version);

        return to_route('monitoring.destinations.show', [$project, $target->id])->with('issued_key', $target->signing_secret);
    }
}
