<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteLogSnapshot extends DeployerModel
{
    public const TYPES = ['application', 'access'];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_REFRESHING = 'refreshing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'log' => 'encrypted',
        'refreshed_at' => 'datetime',
    ];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
