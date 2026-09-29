<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\EnableDatabaseRecovery;
use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EnableDatabaseRecoveryController
{
    /**
     * Turn on continuous backup for the database server and return to its recovery tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  EnableDatabaseRecovery  $enable
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, EnableDatabaseRecovery $enable): RedirectResponse
    {
        $data = $request->validate(['backup_destination_id' => ['required', 'integer'], 'retention_days' => ['required', 'integer', 'between:1,35']]);
        $destination = BackupDestination::query()->where('account_id', $server->account_id)->findOrFail((int) $data['backup_destination_id']);
        $enable->handle($user, $server, $destination, (int) $data['retention_days']);

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'recovery'])->with('status', __('Setting up continuous backup. It shows in the server’s command history.'));
    }
}
