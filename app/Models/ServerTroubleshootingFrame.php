<?php

namespace App\Models;

use App\Enums\ServerTroubleshootingFrameDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerTroubleshootingFrame extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = [
        'direction' => ServerTroubleshootingFrameDirection::class,
        'payload' => 'encrypted',
        'sequence' => 'integer',
        'payload_bytes' => 'integer',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /** @return BelongsTo<ServerTroubleshootingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ServerTroubleshootingSession::class, 'server_troubleshooting_session_id');
    }

    /** Return whether an input frame has been claimed before a remote write. */
    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /** Return whether an output frame has been acknowledged by its reader. */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
