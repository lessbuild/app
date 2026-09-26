<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dashboard_id', 'type', 'position', 'configuration'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'configuration' => 'array'];
    }
}
