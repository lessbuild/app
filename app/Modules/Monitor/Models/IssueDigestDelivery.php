<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IssueDigestDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'recipient_id',
    'recipient_email',
    'period_start',
    'period_end',
    'status',
    'attempts',
    'new_count',
    'resolved_count',
    'open_count',
    'critical_open_count',
    'last_error_code',
    'sending_started_at',
    'sent_at',
    'failed_at',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class IssueDigestDelivery extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @use HasFactory<IssueDigestDeliveryFactory> */
    use HasFactory;

    /** @param Builder<IssueDigestDelivery> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereBelongsTo($workspace);
    }

    /** @param Builder<IssueDigestDelivery> $query */
    #[Scope]
    protected function forRecipient(Builder $query, User $user): void
    {
        $query->whereBelongsTo($user, 'recipient');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'attempts' => 'integer',
            'new_count' => 'integer',
            'resolved_count' => 'integer',
            'open_count' => 'integer',
            'critical_open_count' => 'integer',
            'sending_started_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
