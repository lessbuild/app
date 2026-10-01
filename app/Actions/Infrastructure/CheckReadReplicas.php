<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Server;
use App\Notifications\ReplicationBrokenNotification;
use App\Services\Infrastructure\ReplicationScripts;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class CheckReadReplicas
{
    /**
     * Create a new CheckReadReplicas instance.
     *
     * @param  ServerShell  $shell  Runs the checks on the replicas.
     * @param  ReplicationScripts  $scripts  Renders the checks.
     */
    public function __construct(private readonly ServerShell $shell, private readonly ReplicationScripts $scripts) {}

    /**
     * Check every read replica: follow setups still copying, and record how far each streaming replica is behind its
     * primary. A replica that stops copying is marked broken and the account's owners hear once. Returns how many
     * replicas were checked.
     *
     * @return int
     */
    public function handle(): int
    {
        $checked = 0;
        Server::query()->with(['replicaOf', 'account'])->whereNotNull('replica_of_server_id')
            ->whereIn('replication_status', ['setting_up', 'streaming', 'broken'])
            ->each(function (Server $replica) use (&$checked): void {
                try {
                    $this->check($replica);
                    $checked++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $checked;
    }

    /**
     * Check one replica and record what it reports.
     *
     * @param  Server  $replica
     * @return void
     */
    private function check(Server $replica): void
    {
        if ($replica->replication_status === 'setting_up') {
            $state = trim($this->shell->run($replica, $this->scripts->setupState())->output);
            if ($state === '' || $state === 'running') {
                return;
            }
            if (str_starts_with($state, 'failed')) {
                $replica->forceFill(['replication_status' => 'failed', 'replication_error' => mb_substr(trim(substr($state, 6)) ?: __('The copy failed.'), 0, 1000), 'replication_checked_at' => now()])->save();

                return;
            }
        }

        $result = $this->shell->run($replica, $this->scripts->status($replica));
        $status = $this->scripts->parseStatus($result->output);
        $wasBroken = $replica->replication_status === 'broken';
        $replica->forceFill([
            'replication_status' => $status['running'] ? 'streaming' : 'broken',
            'replication_lag_seconds' => $status['lag'],
            'replication_error' => $status['running'] ? null : ($status['error'] ?? __('The replica isn’t following its primary.')),
            'replication_checked_at' => now(),
        ])->save();

        if (! $status['running'] && ! $wasBroken) {
            $owners = $replica->account->members()->wherePivot('role', AccountRole::Owner->value)->get();
            $project = $replica->account->projects()->whereHas('enabledServices', fn ($query) => $query->where('service', 'infrastructure'))->orderBy('created_at')->first();
            Notification::send($owners, new ReplicationBrokenNotification($replica, $project !== null ? route('infrastructure.servers.show', [$project, $replica->id, 'tab' => 'replicas']) : route('dashboard')));
        }
    }
}
