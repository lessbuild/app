<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AlertOutboundDelivery extends DeployerModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const MAX_ATTEMPTS_PER_CYCLE = 3;

    public const MAX_MANUAL_RETRIES = 1;

    public const RETRY_WINDOW_HOURS = 72;

    protected $guarded = [];

    protected $hidden = ['processing_token'];

    protected $casts = [
        'attempt_count' => 'integer',
        'cycle_attempts' => 'integer',
        'manual_retry_count' => 'integer',
        'generation' => 'integer',
        'http_status' => 'integer',
        'dispatched_at' => 'immutable_datetime',
        'next_attempt_at' => 'immutable_datetime',
        'retry_available_until' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AlertDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    /** @return HasOne<AlertOutboundDeliveryPayload, $this> */
    public function payloadRecord(): HasOne
    {
        return $this->hasOne(AlertOutboundDeliveryPayload::class, 'alert_outbound_delivery_id');
    }

    /** @return HasMany<AlertOutboundDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AlertOutboundDeliveryAttempt::class, 'alert_outbound_delivery_id');
    }
}
