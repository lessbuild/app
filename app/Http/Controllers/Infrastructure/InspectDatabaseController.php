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
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, RequestDatabaseInspection $inspect): RedirectResponse
    {
        $snapshot = $inspect->handle($website, $user);

        return to_route('infrastructure.websites.show', [$project, $website->id])->withFragment('database')
            ->with('status', $snapshot === null ? __('An inspection is already running.') : __('Inspecting the database.'));
    }
}
