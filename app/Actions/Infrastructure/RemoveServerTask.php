<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\SyncServerTask;
use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Illuminate\Support\Facades\Gate;

final class RemoveServerTask
{
    /**
     * Create a new RemoveServerTask instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Take a cron job, process or firewall rule off its server (queued); it's deleted once the server confirms. One
     * that never got onto the server is deleted straight away.
     *
     * @param  User  $actor
     * @param  ServerCronJob|ServerProcess|ServerFirewallRule  $task
     * @return void
     */
    public function handle(User $actor, ServerCronJob|ServerProcess|ServerFirewallRule $task): void
    {
        Gate::forUser($actor)->authorize('runCommands', $task->server);
        $this->audit->handle(AuditAction::ServerTaskRemoved, $actor, $task->server->account_id, ['task' => ServerTaskKinds::describe($task), 'server' => $task->server->label()]);
        if ($task->applied_at === null && $task->status !== 'active') {
            $task->delete();

            return;
        }
        $task->forceFill(['status' => 'removing'])->save();
        SyncServerTask::dispatch($task::class, $task->id, 'remove')->afterCommit();
    }
}
