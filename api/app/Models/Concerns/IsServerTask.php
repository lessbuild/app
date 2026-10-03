<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Server;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared by what a server runs besides its websites (cron jobs, processes, firewall rules): its server and state. */
trait IsServerTask
{
    /**
     * Get the server it runs on.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }
}
