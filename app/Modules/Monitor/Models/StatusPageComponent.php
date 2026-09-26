<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\StatusPageComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['status_page_id', 'monitor_id', 'label', 'position'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class StatusPageComponent extends Model
{
    /** @use HasFactory<StatusPageComponentFactory> */
    use HasFactory;

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
