<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\QueueWebsiteBackup;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreWebsiteBackupController
{
    /**
     * Start a backup now, unless one is already running.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  QueueWebsiteBackup  $queue
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, QueueWebsiteBackup $queue): JsonResponse
    {
        $request->validate(['backup_destination_id' => ['required', 'integer']]);
        $destination = BackupDestination::query()->where('account_id', $website->account_id)->findOrFail($request->integer('backup_destination_id'));
        $backup = $queue->handle($website, $destination, $user);

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'], false), 'message' => $backup === null ? __('A backup is already running for this website.') : __('Backup started.')]);
    }
}
