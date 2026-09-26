<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

final class BlueprintApplicationReceipt extends DeployerModel
{
    protected $guarded = [];

    protected $casts = [
        'result' => 'array',
        'completed_at' => 'datetime',
    ];
}
