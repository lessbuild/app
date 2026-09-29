<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IncidentActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $incident_id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Incident $incident
 * @property-read User|null $actor
 */
#[Fillable(['actor_id', 'action', 'note', 'metadata'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(IncidentActivityFactory::class)]
class IncidentActivity extends Model
{
    /** @use HasFactory<IncidentActivityFactory> */
    use HasFactory;

    /**
     * Get the incident the activity is on.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the person who did it (`actor_id`); null for automatic entries.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Describe the activity as a line on the timeline.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this->action) {
            'opened' => 'Threshold breached',
            'postmortem_saved' => 'Post-mortem written',
            'postmortem_published' => 'Post-mortem published to a status page',
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

    /**
     * Get the attributes that should be cast.
     *
     * Reads `metadata` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
