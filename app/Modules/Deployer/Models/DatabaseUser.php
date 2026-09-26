<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends DeployerModel
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'encrypted', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];

    /** @return BelongsTo<EnvironmentResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'environment_resource_id');
    }
}
