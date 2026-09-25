<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

final class ProductDeletionReceipt extends DeployerModel
{
    protected $table = 'product_deletion_receipts';

    protected $guarded = [];

    protected $casts = ['retained' => 'array'];
}
