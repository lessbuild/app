<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ScheduleDeploy;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreScheduledDeployController
{
    /**
     * Book a deploy of the repository for later, read in the chosen time zone, and return to the repository.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  ScheduleDeploy  $schedule
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, ScheduleDeploy $schedule): RedirectResponse
    {
        $data = $request->validate(['deploy_at' => ['required', 'date'], 'timezone' => ['required', 'timezone:all'], 'ref' => ['nullable', 'string', 'max:200']]);
        $booked = $schedule->handle($user, $repository, CarbonImmutable::parse((string) $data['deploy_at'], (string) $data['timezone']), $data['ref'] ?? null);

        return to_route('deploy.repositories.show', [$project, $repository->id])
            ->with('status', __('Deploy booked for :time UTC.', ['time' => $booked->run_at->toDayDateTimeString()]));
    }
}
