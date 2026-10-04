<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RestoreWebsiteBackup;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RestoreWebsiteBackupController
{
    /**
     * Start restoring a website from a backup; a failed restore puts the website back as it was.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $backup
     * @param  RestoreWebsiteBackup  $restore
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $backup, RestoreWebsiteBackup $restore): JsonResponse
    {
        $restore->handle($user, $website->backups()->findOrFail((int) $backup));

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'], false), 'message' => __('Restore started. If any step fails, the website is put back as it was.')]);
    }
}
