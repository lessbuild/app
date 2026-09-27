<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\QueueWebsiteBackup;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreWebsiteBackupController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, QueueWebsiteBackup $queue): RedirectResponse
    {
        $request->validate(['backup_destination_id' => ['required', 'integer']]);
        $destination = BackupDestination::query()->where('account_id', $website->account_id)->findOrFail($request->integer('backup_destination_id'));
        $backup = $queue->handle($website, $destination, $user);

        return to_route('infrastructure.websites.show', [$project, $website->id])->withFragment('backups')
            ->with('status', $backup === null ? __('A backup is already running for this website.') : __('Backup started.'));
    }
}
