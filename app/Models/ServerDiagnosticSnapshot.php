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
 * The latest diagnostic run for a server: each check passed or not, with a short detail.
 *
 * @property int $id
 * @property int $server_id
 * @property string $status queued, running, ready or failed
 * @property list<array{name: string, category: string, passed: bool, detail: string}>|null $checks
 * @property string|null $failure_stage server_state, host_identity, transport or response
 * @property string|null $error
 * @property int $attempt
 * @property string|null $attempt_token
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Hidden(['attempt_token'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerDiagnosticSnapshot extends Model
{
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true) && ($this->lease_expires_at === null || $this->lease_expires_at->isFuture());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checks' => 'array', 'attempt' => 'integer', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
