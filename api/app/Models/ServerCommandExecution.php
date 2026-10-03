<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shell command someone ran on a server as root. The command and its output are encrypted.
 *
 * @property int $id
 * @property int $server_id
 * @property string|null $user_id
 * @property int|null $rerun_from_execution_id
 * @property string $command
 * @property string $status queued, running, succeeded, failed or canceled
 * @property string|null $output
 * @property int|null $exit_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read User|null $user
 */
#[Hidden(['command', 'output'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerCommandExecution extends Model
{
    public const ACTIVE = ['queued', 'running'];

    public const FINISHED = ['succeeded', 'failed', 'canceled'];

    /**
     * Get the server the command ran on.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the person who ran the command.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether the command has finished, successfully or not.
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /**
     * Get how long the command ran; null until it has started and finished.
     *
     * @return int|null
     */
    public function durationSeconds(): ?int
    {
        return $this->started_at !== null && $this->finished_at !== null ? (int) $this->started_at->diffInSeconds($this->finished_at) : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the command and its output.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['command' => 'encrypted', 'output' => 'encrypted', 'exit_code' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
