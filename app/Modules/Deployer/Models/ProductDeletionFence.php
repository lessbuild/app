<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

final class ProductDeletionFence extends DeployerModel
{
    protected $table = 'product_deletion_fences';

    protected $guarded = [];
}
