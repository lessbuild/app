<?php

namespace App\Core\Contracts;

use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Enums\ProductKey;

interface ProductPlanResolver
{
    public function resolve(string $workspaceId, ProductKey $product): ProductPlanResolution;
}
