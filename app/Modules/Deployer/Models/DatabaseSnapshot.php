<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseSnapshot extends DeployerModel
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['size_bytes' => 'integer', 'active_connections' => 'integer', 'slow_queries' => 'integer', 'schema_tables' => 'array', 'collected_at' => 'datetime'];

    /** @return BelongsTo<EnvironmentResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'environment_resource_id');
    }
}
