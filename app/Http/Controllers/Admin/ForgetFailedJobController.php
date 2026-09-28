<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ForgetFailedJobs;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class ForgetFailedJobController
{
    /**
     * Delete one failed job, or all of them (`all`), and return to the queues.
     *
     * @param  User  $user
     * @param  string  $job
     * @param  ForgetFailedJobs  $forget
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $job, ForgetFailedJobs $forget): RedirectResponse
    {
        $forget->handle($user, $job === 'all' ? null : $job);

        return to_route('admin.queues')->with('status', $job === 'all' ? __('Every failed job is deleted.') : __('The job is deleted.'));
    }
}
