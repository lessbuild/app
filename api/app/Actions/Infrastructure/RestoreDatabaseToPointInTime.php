<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\DatabaseBackupPlan;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\Infrastructure\DatabaseRecoveryScripts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestoreDatabaseToPointInTime
{
    /**
     * Create a new RestoreDatabaseToPointInTime instance.
     *
     * @param  RunServerCommand  $run  Runs the restore on the server, in its command history.
     * @param  DatabaseRecoveryScripts  $scripts  Renders the restore.
     */
    public function __construct(private readonly RunServerCommand $run, private readonly DatabaseRecoveryScripts $scripts) {}

    /**
     * Restore a database server to a moment within its continuous backup's window. It's destructive (the current data
     * is moved aside, not deleted), so the server's name must be typed to confirm.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  CarbonImmutable  $at
     * @param  string  $confirmation  the server's name, typed
     * @return ServerCommandExecution
     */
    public function handle(User $actor, Server $server, CarbonImmutable $at, string $confirmation): ServerCommandExecution
    {
        $plan = DatabaseBackupPlan::query()->where('server_id', $server->id)->first()
            ?? throw ValidationException::withMessages(['restore_to' => __('Turn on continuous backup first.')]);
        if (! hash_equals($server->name, trim($confirmation))) {
            throw ValidationException::withMessages(['confirmation' => __('Type the server’s name, :name, to confirm.', ['name' => $server->name])]);
        }
        if ($at->lessThan($plan->earliestRestore()) || $at->isFuture()) {
            throw ValidationException::withMessages(['restore_to' => __('Choose a moment between :from and now (UTC).', ['from' => $plan->earliestRestore()->utc()->format('Y-m-d H:i')])]);
        }

        return DB::transaction(function () use ($actor, $server, $at): ServerCommandExecution {
            $execution = $this->run->handle($server->account, $actor, $server, $this->scripts->restore($server, $at));
            DB::table('database_restores')->insert([
                'server_id' => $server->id, 'restore_to' => $at->utc(), 'execution_id' => $execution->id, 'requested_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $execution;
        });
    }
}
