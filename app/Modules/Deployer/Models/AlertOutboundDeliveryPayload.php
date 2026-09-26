<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertOutboundDeliveryPayload extends DeployerModel
{
    protected $primaryKey = 'alert_outbound_delivery_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'expires_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AlertOutboundDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AlertOutboundDelivery::class, 'alert_outbound_delivery_id');
    }
}
