<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RestoreWebsiteBackup;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RestoreWebsiteBackupController
{
    /**
     * Starts restoring a website from a backup; a failed restore puts the website back as it was.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $backup, RestoreWebsiteBackup $restore): RedirectResponse
    {
        $restore->handle($user, $website->backups()->findOrFail((int) $backup));

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'])->with('status', __('Restore started. If any step fails, the website is put back as it was.'));
    }
}
