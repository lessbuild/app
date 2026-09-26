<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\UsageAlertDeliveryFactory;
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
    'threshold',
    'status',
    'attempts',
    'event_count',
    'event_limit',
    'percentage',
    'last_error_code',
    'sending_started_at',
    'sent_at',
    'failed_at',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class UsageAlertDelivery extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @use HasFactory<UsageAlertDeliveryFactory> */
    use HasFactory;

    /** @param Builder<UsageAlertDelivery> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereBelongsTo($workspace);
    }

    /** @param Builder<UsageAlertDelivery> $query */
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
            'threshold' => 'integer',
            'attempts' => 'integer',
            'event_count' => 'integer',
            'event_limit' => 'integer',
            'percentage' => 'integer',
            'sending_started_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
