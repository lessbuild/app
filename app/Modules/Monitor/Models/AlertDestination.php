<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Database\Factories\AlertDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AlertDestination extends Model
{
    /** @use HasFactory<AlertDestinationFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['endpoint_url', 'signing_secret'];

    /** @param Builder<AlertDestination> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereBelongsTo($workspace);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsToMany<AlertRule, $this> */
    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class)->withPivot(['opened', 'recovered']);
    }

    /** @return BelongsToMany<Monitor, $this> */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['opened', 'recovered']);
    }

    /** @return HasMany<AlertDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /** @return HasMany<AlertEscalation, $this> */
    public function escalations(): HasMany
    {
        return $this->hasMany(AlertEscalation::class);
    }

    public function targetLabel(): string
    {
        return $this->type === AlertDestinationType::Email
            ? ($this->recipient?->name ?? 'Recipient unavailable')
            : (parse_url($this->endpoint_url ?? '', PHP_URL_HOST) ?: 'Endpoint unavailable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertDestinationType::class, 'enabled' => 'boolean',
            'state_version' => 'integer', 'target_revision' => 'integer',
            'endpoint_url' => 'encrypted', 'signing_secret' => 'encrypted',
        ];
    }
}
