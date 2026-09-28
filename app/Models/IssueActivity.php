<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IssueActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $issue_id
 * @property string|null $actor_id
 * @property string $action
 * @property array<string, mixed>|null $metadata
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Issue $issue
 * @property-read User|null $actor
 */
#[Fillable(['actor_id', 'action', 'metadata', 'note'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(IssueActivityFactory::class)]
final class IssueActivity extends Model
{
    /** @use HasFactory<IssueActivityFactory> */
    use HasFactory;

    /**
     * The issue the activity is on.
     *
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * Who did it (`actor_id`); null for automatic entries.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The activity as a line on the timeline.
     *
     * @return string
     */
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

    /**
     * Reads `metadata` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
