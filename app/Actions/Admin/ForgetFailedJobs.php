<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\Admin\PlatformAdmins;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

final class ForgetFailedJobs
{
    /**
     * Create a new ForgetFailedJobs instance.
     *
     * Deletes failed jobs.
     *
     * @param  FailedJobProviderInterface  $failed  The failed job store.
     * @param  PlatformAdmins  $admins  Records it in the admin trail.
     */
    public function __construct(private readonly FailedJobProviderInterface $failed, private readonly PlatformAdmins $admins) {}

    /**
     * Delete one failed job by its UUID, or all of them (null), without running it, and record who did.
     *
     * @param  User  $admin
     * @param  string|null  $uuid
     * @return void
     */
    public function handle(User $admin, ?string $uuid): void
    {
        $uuid === null ? $this->failed->flush() : $this->failed->forget($uuid);
        $this->admins->record($admin, 'jobs.forgotten', $uuid === null ? 'Deleted every failed job.' : "Deleted failed job {$uuid}.");
    }
}
