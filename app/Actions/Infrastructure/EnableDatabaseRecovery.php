<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\ServerType;
use App\Models\BackupDestination;
use App\Models\DatabaseBackupPlan;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\DatabaseRecoveryScripts;
use Illuminate\Validation\ValidationException;

final class EnableDatabaseRecovery
{
    /**
     * Create a new EnableDatabaseRecovery instance.
     *
     * @param  RunServerCommand  $run  Runs the setup on the server, in its command history.
     * @param  DatabaseRecoveryScripts  $scripts  Renders the setup.
     */
    public function __construct(private readonly RunServerCommand $run, private readonly DatabaseRecoveryScripts $scripts) {}

    /**
     * Turn on continuous backup for a database server into one of the account's backup destinations, keeping
     * 1–35 days. Running it again (e.g. with a new destination or retention) sets it up afresh.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  BackupDestination  $destination
     * @param  int  $retentionDays
     * @return DatabaseBackupPlan
     */
    public function handle(User $actor, Server $server, BackupDestination $destination, int $retentionDays): DatabaseBackupPlan
    {
        if ($server->type !== ServerType::Database || $server->database_engine === null) {
            throw ValidationException::withMessages(['backup_destination_id' => __('Point-in-time recovery is for database servers.')]);
        }
        if ($destination->account_id !== $server->account_id) {
            throw ValidationException::withMessages(['backup_destination_id' => __('Choose one of this account’s backup destinations.')]);
        }
        if ($retentionDays < 1 || $retentionDays > 35) {
            throw ValidationException::withMessages(['retention_days' => __('Keep backups for 1 to 35 days.')]);
        }
        $execution = $this->run->handle($server->account, $actor, $server, $this->scripts->enable($server, $destination, $retentionDays));
        $plan = DatabaseBackupPlan::query()->where('server_id', $server->id)->first() ?? (new DatabaseBackupPlan)->forceFill(['server_id' => $server->id]);
        $plan->forceFill(['backup_destination_id' => $destination->id, 'retention_days' => $retentionDays, 'enabled_at' => now(), 'setup_execution_id' => $execution->id])->save();

        return $plan;
    }
}
