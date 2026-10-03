<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\Admin\PlatformAdmins;
use Illuminate\Support\Facades\Artisan;

final class RetryFailedJobs
{
    /**
     * Create a new RetryFailedJobs instance.
     *
     * Puts failed jobs back on their queues.
     *
     * @param  PlatformAdmins  $admins  Records it in the admin trail.
     */
    public function __construct(private readonly PlatformAdmins $admins) {}

    /**
     * Queue one failed job again by its UUID, or every failed job (null), and record who did.
     *
     * @param  User  $admin
     * @param  string|null  $uuid
     * @return void
     */
    public function handle(User $admin, ?string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid ?? 'all']]);
        $this->admins->record($admin, 'jobs.retried', $uuid === null ? 'Retried every failed job.' : "Retried failed job {$uuid}.");
    }
}
