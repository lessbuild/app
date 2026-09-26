<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertOutboundDeliveryAttempt extends DeployerModel
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected $casts = [
        'number' => 'integer',
        'http_status' => 'integer',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AlertOutboundDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AlertOutboundDelivery::class, 'alert_outbound_delivery_id');
    }
}
