<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event sent (or being sent) to a webhook endpoint.
 *
 * @property string $id also sent as X-BuildPusher-Delivery, so receivers can ignore repeats
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status pending, delivered or failed
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
final class WebhookDelivery extends Model
{
    use HasUlids;
    use MassPrunable;

    /**
     * Deliveries are kept this many days; `model:prune` deletes older ones.
     *
     * @var int
     */
    public const RETENTION_DAYS = 30;

    /**
     * How many times a delivery is tried before it counts as failed.
     *
     * @var int
     */
    public const MAX_ATTEMPTS = 6;

    /**
     * The attributes that can't be mass assigned: all of them; deliveries are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    /**
     * Get the endpoint it's for.
     *
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Get the deliveries old enough to delete.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
