<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentProcess extends DeployerModel
{
    public const TYPES = ['worker', 'scheduler'];

    protected $guarded = [];

    protected $hidden = ['command'];

    protected $casts = ['command' => 'encrypted', 'replicas' => 'integer', 'restart_delay_seconds' => 'integer', 'is_enabled' => 'boolean', 'is_preview_owned' => 'boolean'];

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
