<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\PlatformAdmins;
use App\Services\Admin\PlatformBackups;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RunPlatformBackupController
{
    /**
     * Back the platform database up now, record it in the admin trail, and show how it went.
     *
     * @param  User  $user
     * @param  PlatformBackups  $backups
     * @param  PlatformAdmins  $admins
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, PlatformBackups $backups, PlatformAdmins $admins): RedirectResponse
    {
        $backup = $backups->create('manual');
        $admins->record($user, 'backup.created', $backup->succeeded() ? "Backed up the database to {$backup->file}" : 'A database backup failed');

        return to_route('admin.backups')->with($backup->succeeded() ? 'status' : 'error', $backup->succeeded()
            ? ($backup->isOffsite() ? __('Backed up and copied off-site.') : __('Backed up on this server.').($backup->error ? ' '.$backup->error : ''))
            : __('The backup failed: :error', ['error' => $backup->error]));
    }
}
