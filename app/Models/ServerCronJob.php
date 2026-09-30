<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsServerTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A command a server runs on a schedule, written to /etc/cron.d.
 *
 * @property int $id
 * @property int $server_id
 * @property string $command
 * @property string $user the Linux user it runs as
 * @property string $frequency a five-field cron expression
 * @property string $status pending, active, removing or failed
 * @property string|null $error
 * @property CarbonImmutable|null $applied_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class ServerCronJob extends Model
{
    use IsServerTask;

    /**
     * Common schedules, as cron expressions and what they mean.
     *
     * @var array<string, string>
     */
    public const PRESETS = ['* * * * *' => 'Every minute', '*/5 * * * *' => 'Every five minutes', '0 * * * *' => 'Every hour', '0 3 * * *' => 'Every night at 03:00', '0 3 * * 0' => 'Every Sunday at 03:00'];

    /**
     * Describe the schedule, in words when it's a common one.
     *
     * @return string
     */
    public function schedule(): string
    {
        return __(self::PRESETS[$this->frequency] ?? $this->frequency);
    }
}
