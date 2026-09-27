<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StatusUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An incident or maintenance notice the team posts on a status page. Subscribers get an email each time it's posted or changed.
 *
 * @property int $id
 * @property int $status_page_id
 * @property string|null $created_by
 * @property string $kind
 * @property string $status
 * @property string $severity
 * @property string $title
 * @property string $message
 * @property string|null $root_cause
 * @property string|null $remediation
 * @property string|null $follow_up
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StatusPage $statusPage
 * @property-read User|null $creator
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(StatusUpdateFactory::class)]
class StatusUpdate extends Model
{
    /** @use HasFactory<StatusUpdateFactory> */
    use HasFactory;

    public const KINDS = ['incident', 'maintenance'];

    /** Statuses for each kind, in the order they usually happen. The last one closes the update. */
    public const STATUSES = [
        'incident' => ['investigating', 'identified', 'monitoring', 'resolved'],
        'maintenance' => ['scheduled', 'in_progress', 'completed'],
    ];

    public const SEVERITIES = ['minor', 'major', 'critical'];

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['resolved', 'completed'], true);
    }

    public function statusLabel(): string
    {
        return __(str_replace('_', ' ', ucfirst($this->status)));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
