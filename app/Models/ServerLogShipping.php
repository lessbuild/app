<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server sending its logs (system warnings and errors, and its websites' Laravel logs) to a Monitoring environment,
 * through a small agent installed on it.
 *
 * @property int $id
 * @property int $server_id
 * @property string $environment_id
 * @property int|null $ingest_token_id the key the agent sends with
 * @property string $status installing, active, failed or removing
 * @property string|null $last_error
 * @property Carbon|null $installed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Environment $environment
 * @property-read IngestToken|null $ingestToken
 */
final class ServerLogShipping extends Model
{
    /**
     * The table's name.
     *
     * @var string
     */
    protected $table = 'server_log_shipping';

    /**
     * The attributes that can't be mass assigned: all of them; rows are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['installed_at' => 'datetime'];
    }

    /**
     * Get the server sending logs.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the environment the logs go to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the key the agent sends with.
     *
     * @return BelongsTo<IngestToken, $this>
     */
    public function ingestToken(): BelongsTo
    {
        return $this->belongsTo(IngestToken::class);
    }
}
