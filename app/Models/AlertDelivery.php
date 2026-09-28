<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertDeliveryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AlertDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $account_id
 * @property int $alert_destination_id
 * @property int|null $incident_id
 * @property string $event
 * @property int $target_revision
 * @property array<string, mixed> $payload
 * @property AlertDeliveryStatus $status
 * @property int $generation
 * @property int $attempt_count
 * @property int $cycle_attempts
 * @property string|null $queue_job_uuid
 * @property string|null $processing_token
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error_code
 * @property int|null $http_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read AlertDestination $destination
 * @property-read Incident|null $incident
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlertDeliveryAttempt> $attempts
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(AlertDeliveryFactory::class)]
class AlertDelivery extends Model
{
    /** @use HasFactory<AlertDeliveryFactory> */
    use HasFactory, HasUlids;

    /**
     * The payload and queue bookkeeping never leave the server in serialised form.
     *
     * @var list<string>
     */
    protected $hidden = ['payload', 'processing_token', 'queue_job_uuid'];

    /**
     * Limits a query to the account's deliveries.
     *
     * @param  Builder<AlertDelivery>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereBelongsTo($account);
    }

    /**
     * The account the delivery belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Where the alert is going, including archived destinations so history still reads.
     *
     * @return BelongsTo<AlertDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id')->withTrashed();
    }

    /**
     * The incident the alert is about.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Each try at sending.
     *
     * @return HasMany<AlertDeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(AlertDeliveryAttempt::class);
    }

    /**
     * Encrypts `payload` (it can contain incident details) and reads `status` as an AlertDeliveryStatus.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AlertDeliveryStatus::class, 'payload' => 'encrypted:array',
            'generation' => 'integer', 'attempt_count' => 'integer', 'cycle_attempts' => 'integer',
            'target_revision' => 'integer', 'http_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime',
        ];
    }
}
