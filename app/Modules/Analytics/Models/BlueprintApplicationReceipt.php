<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;

final class BlueprintApplicationReceipt extends AnalyticsModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }
}
