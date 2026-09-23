<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteHealthCheck extends DeployerModel
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTOMATIC = 'automatic';

    public const MAX_PER_WEBSITE = 100;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'successful' => 'boolean',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
