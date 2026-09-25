<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

final class ProductDeletionActivityClaim extends DeployerModel
{
    protected $table = 'product_deletion_activity_claims';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }
}
