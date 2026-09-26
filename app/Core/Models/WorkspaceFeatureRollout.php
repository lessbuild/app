<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;

final class WorkspaceFeatureRollout extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
