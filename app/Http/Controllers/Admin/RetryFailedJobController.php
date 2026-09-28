<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RetryFailedJobs;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryFailedJobController
{
    /**
     * Retry one failed job, or all of them (`all`), and return to the queues.
     *
     * @param  User  $user
     * @param  string  $job
     * @param  RetryFailedJobs  $retry
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $job, RetryFailedJobs $retry): RedirectResponse
    {
        $retry->handle($user, $job === 'all' ? null : $job);

        return to_route('admin.queues')->with('status', $job === 'all' ? __('Every failed job is queued again.') : __('The job is queued again.'));
    }
}
