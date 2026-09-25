<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['step_id', 'workspace_source_id', 'actor_source_id', 'canonical_project_id', 'payload_hash', 'result', 'completed_at'])]
class BlueprintApplicationReceipt extends Model
{
    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
