<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IncidentActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'note', 'metadata'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class IncidentActivity extends Model
{
    /** @use HasFactory<IncidentActivityFactory> */
    use HasFactory;

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return match ($this->action) {
            'opened' => 'Threshold breached',
            'acknowledge' => 'Acknowledged',
            'note' => 'Note added',
            'assign' => ($this->metadata['assignee_id'] ?? null) === null ? 'Unassigned' : 'Assignee changed',
            'recovered' => 'Automatically recovered',
            'rule_changed' => 'Closed because monitoring conditions changed',
            'rule_archived' => 'Closed because the rule was archived',
            'rule_paused' => 'Evaluations paused',
            'rule_resumed' => 'Evaluations resumed',
            'monitor_failed' => 'Uptime checks failed',
            'monitor_changed' => 'Closed because monitor conditions changed',
            'monitor_archived' => 'Closed because the monitor was archived',
            'monitor_paused' => 'Uptime checks paused',
            'monitor_resumed' => 'Uptime checks resumed',
            default => 'Incident updated',
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
