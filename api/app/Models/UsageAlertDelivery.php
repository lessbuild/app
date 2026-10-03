<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A usage alert email for one person, month and threshold. Status is sending, sent or failed; a send stuck for 15 minutes may be retried.
 *
 * @property int $id
 * @property string $account_id
 * @property string $recipient_id
 * @property CarbonImmutable $period_start
 * @property int $threshold
 * @property int $event_count
 * @property int $event_limit
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $sending_started_at
 * @property CarbonImmutable|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class UsageAlertDelivery extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'sending_started_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
