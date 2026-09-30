<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\EnableSlowQueryLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class EnableSlowQueryLogController
{
    /**
     * Turn on the slow query log and return to the website's database.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  EnableSlowQueryLog  $enable
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, EnableSlowQueryLog $enable): RedirectResponse
    {
        $enable->handle($user, $website);

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'database'])->with('status', __('Turning on the slow query log. Slow queries show here from the next inspection.'));
    }
}
