<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use App\Modules\Deployer\Enums\ServerTroubleshootingSessionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerTroubleshootingSession extends DeployerModel
{
    public const STATUS_CONNECTING = ServerTroubleshootingSessionStatus::Connecting->value;

    public const STATUS_CONNECTED = ServerTroubleshootingSessionStatus::Connected->value;

    public const STATUS_CLOSING = ServerTroubleshootingSessionStatus::Closing->value;

    public const STATUS_CLOSED = ServerTroubleshootingSessionStatus::Closed->value;

    public const STATUS_EXPIRED = ServerTroubleshootingSessionStatus::Expired->value;

    public const STATUS_REVOKED = ServerTroubleshootingSessionStatus::Revoked->value;

    public const STATUS_FAILED = ServerTroubleshootingSessionStatus::Failed->value;

    public const ACTIVE_STATUSES = ServerTroubleshootingSessionStatus::ACTIVE_VALUES;

    public const TERMINAL_STATUSES = ServerTroubleshootingSessionStatus::TERMINAL_VALUES;

    public const CLOSE_REASON_USER = 'user';

    public const CLOSE_REASON_EXPIRED = 'expired';

    public const CLOSE_REASON_REVOKED = 'authorization_changed';

    public const CLOSE_REASON_SERVER_INACTIVE = 'server_inactive';

    public const CLOSE_REASON_TRANSPORT = 'transport_failure';

    protected $guarded = [];

    protected $hidden = ['grant_hash', 'broker_lease_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'connected_at' => 'datetime',
        'closed_at' => 'datetime',
        'broker_lease_expires_at' => 'datetime',
        'broker_attempt' => 'integer',
        'broker_process_id' => 'integer',
        'input_sequence' => 'integer',
        'output_sequence' => 'integer',
    ];

    /** Use the opaque UUID when a future transport binds a session from a URL. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ServerTroubleshootingFrame, $this> */
    public function frames(): HasMany
    {
        return $this->hasMany(ServerTroubleshootingFrame::class);
    }

    /** Return the known lifecycle state without changing its stored string. */
    public function statusEnum(): ?ServerTroubleshootingSessionStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof ServerTroubleshootingSessionStatus
            ? $status
            : (is_string($status) ? ServerTroubleshootingSessionStatus::tryFrom($status) : null);
    }

    /** Return whether this session is still in a transport-owned state. */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Return whether the absolute or idle deadline has passed. */
    public function hasExpired(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return ($this->expires_at?->lessThanOrEqualTo($at) ?? true)
            || ($this->idle_expires_at?->lessThanOrEqualTo($at) ?? true);
    }

    /** Return whether a broker lease exists but no longer protects its process. */
    public function brokerLeaseExpired(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->broker_lease_expires_at?->lessThanOrEqualTo($at) ?? false;
    }

    /** Compare a presented grant with the one-way digest retained by the database. */
    public function matchesGrant(string $token): bool
    {
        return filled($this->grant_hash)
            && hash_equals((string) $this->grant_hash, hash('sha256', $token));
    }
}
