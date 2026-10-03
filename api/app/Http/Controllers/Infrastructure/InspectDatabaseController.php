<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RequestDatabaseInspection;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class InspectDatabaseController
{
    /**
     * Start a database inspection, unless one is already running.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  RequestDatabaseInspection  $inspect
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, RequestDatabaseInspection $inspect): RedirectResponse
    {
        $snapshot = $inspect->handle($website, $user);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'])
            ->with('status', $snapshot === null ? __('An inspection is already running.') : __('Inspecting the database.'));
    }
}
