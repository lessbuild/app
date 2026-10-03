<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsServerTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A long-running command (a queue worker, Horizon, Reverb…) that Supervisor keeps running and restarts if it stops.
 *
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $command
 * @property string|null $directory where it runs
 * @property string $user the Linux user it runs as
 * @property int $processes how many copies run
 * @property int $stop_wait_seconds how long a stopping process may finish its work
 * @property string $status pending, active, removing or failed
 * @property string|null $error
 * @property CarbonImmutable|null $applied_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class ServerProcess extends Model
{
    use IsServerTask;

    /**
     * Common processes, filled into the Add a process form.
     *
     * @var array<string, array{name: string, command: string, processes: int, stop_wait_seconds: int}>
     */
    public const PRESETS = [
        'queue' => ['name' => 'Queue worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600', 'processes' => 2, 'stop_wait_seconds' => 3600],
        'horizon' => ['name' => 'Horizon', 'command' => 'php artisan horizon', 'processes' => 1, 'stop_wait_seconds' => 3600],
        'reverb' => ['name' => 'Reverb', 'command' => 'php artisan reverb:start --host=127.0.0.1 --port=8080', 'processes' => 1, 'stop_wait_seconds' => 10],
        'pulse' => ['name' => 'Pulse', 'command' => 'php artisan pulse:check', 'processes' => 1, 'stop_wait_seconds' => 10],
        'schedule' => ['name' => 'Scheduler', 'command' => 'php artisan schedule:work', 'processes' => 1, 'stop_wait_seconds' => 60],
    ];

    /**
     * Get the name Supervisor knows it by.
     *
     * @return string
     */
    public function programName(): string
    {
        return 'bp-process-'.$this->id;
    }
}
