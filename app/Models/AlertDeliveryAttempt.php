<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertDeliveryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AlertDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $alert_delivery_id
 * @property int $number
 * @property AlertDeliveryStatus $status
 * @property string|null $error_code
 * @property int|null $http_status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read AlertDelivery $delivery
 */
#[UseFactory(AlertDeliveryAttemptFactory::class)]
class AlertDeliveryAttempt extends Model
{
    /** @use HasFactory<AlertDeliveryAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<AlertDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AlertDelivery::class, 'alert_delivery_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AlertDeliveryStatus::class, 'number' => 'integer', 'http_status' => 'integer',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }
}
