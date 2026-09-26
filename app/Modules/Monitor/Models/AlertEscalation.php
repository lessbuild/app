<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\AlertEscalationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alert_rule_id', 'alert_destination_id', 'delay_minutes', 'position', 'enabled'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AlertEscalation extends Model
{
    /** @use HasFactory<AlertEscalationFactory> */
    use HasFactory;

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    /** @return BelongsTo<AlertDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delay_minutes' => 'integer', 'position' => 'integer', 'enabled' => 'boolean'];
    }
}
