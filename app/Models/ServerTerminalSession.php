<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A root shell on a server for one person, in one browser session. A queued broker (`RunServerTerminal`) holds the SSH
 * connection and relays frames; the browser posts input and polls output. It closes when the shell exits, the person
 * closes it, it's idle too long, or it reaches its time limit.
 *
 * @property string $id
 * @property int $server_id
 * @property string $user_id
 * @property string $token_hash SHA-256 of the token kept in the opener's browser session
 * @property string $status connecting, connected, closed, expired or failed
 * @property string|null $close_reason
 * @property int $columns
 * @property int $rows
 * @property int $input_sequence
 * @property int $output_sequence
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $idle_expires_at
 * @property CarbonImmutable|null $broker_seen_at
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read User $user
 */
#[Hidden(['token_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerTerminalSession extends Model
{
    use HasUlids;

    public const ACTIVE = ['connecting', 'connected'];

    /**
     * The server the terminal is on.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Who opened it; nobody else may use it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Keystrokes and output waiting to be relayed.
     *
     * @return HasMany<ServerTerminalFrame, $this>
     */
    public function frames(): HasMany
    {
        return $this->hasMany(ServerTerminalFrame::class);
    }

    /**
     * Whether the terminal is connecting or connected.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * Whether it has run past its time limit or sat idle too long.
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        return $this->expires_at->isPast() || $this->idle_expires_at->isPast();
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'integer', 'rows' => 'integer', 'input_sequence' => 'integer', 'output_sequence' => 'integer',
            'expires_at' => 'immutable_datetime', 'idle_expires_at' => 'immutable_datetime', 'broker_seen_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime',
        ];
    }
}
