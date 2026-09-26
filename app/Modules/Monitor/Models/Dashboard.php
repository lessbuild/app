<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'created_by', 'name', 'description', 'range'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Dashboard extends Model
{
    public const RANGES = [
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    public const WIDGET_TYPES = [
        'telemetry' => 'Telemetry summary',
        'event_mix' => 'Event mix',
        'incidents' => 'Active incidents',
        'monitors' => 'Monitor health',
        'objectives' => 'SLO health',
        'applications' => 'Applications',
    ];

    /** @use HasFactory<DashboardFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }
}
