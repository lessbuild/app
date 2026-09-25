<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;

final class WorkspaceFeatureRolloutMetric extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date', 'last_exposed_at' => 'datetime', 'last_failure_at' => 'datetime'];
    }
}
