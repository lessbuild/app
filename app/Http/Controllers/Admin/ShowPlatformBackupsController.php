<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\PlatformBackup;
use App\Services\Admin\PlatformBackups;
use Illuminate\Contracts\View\View;

final class ShowPlatformBackupsController
{
    /**
     * Show recent backups of the platform database, whether off-site copies are set up, and how to restore.
     *
     * @param  PlatformBackups  $backups
     * @return View
     */
    public function __invoke(PlatformBackups $backups): View
    {
        return view('admin.backups', [
            'backups' => PlatformBackup::query()->latest('id')->limit(50)->get(),
            'offsite' => $backups->offsiteConfigured(),
            'latest' => $backups->latestSuccessful(),
        ]);
    }
}
