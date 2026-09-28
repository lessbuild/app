<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\VerifyWebsiteBackup;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class VerifyWebsiteBackupController
{
    /**
     * Starts checking that a backup can be restored, without touching the live website.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $backup
     * @param  VerifyWebsiteBackup  $verify
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $backup, VerifyWebsiteBackup $verify): RedirectResponse
    {
        $verify->handle($user, $website->backups()->findOrFail((int) $backup));

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'])->with('status', __('Verification started. The live website isn’t touched.'));
    }
}
