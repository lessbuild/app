<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceUsagePeriod extends AnalyticsModel
{
    protected $fillable = ['workspace_id', 'period_start', 'accepted_events'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'accepted_events' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
