<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IssueActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'action', 'metadata', 'note'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class IssueActivity extends Model
{
    /** @use HasFactory<IssueActivityFactory> */
    use HasFactory;

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return match ($this->action) {
            'detected' => 'Issue detected',
            'resolve' => 'Marked resolved',
            'reopen' => 'Reopened',
            'ignore' => 'Ignored future occurrences',
            'snooze' => 'Snoozed',
            'assign' => ($this->metadata['assignee_id'] ?? null) === null ? 'Unassigned' : 'Assignee changed',
            'regressed' => 'Reopened after a new failure',
            'snooze_expired' => 'Snooze expired',
            'assignee_unavailable' => 'Unassigned: teammate is no longer a contributor',
            default => 'Issue updated',
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
