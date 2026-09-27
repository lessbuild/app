<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlertEscalationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alert_rule_id
 * @property int $alert_destination_id
 * @property int $delay_minutes
 * @property int $position
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AlertRule $alertRule
 * @property-read AlertDestination $destination
 */
#[Fillable(['alert_rule_id', 'alert_destination_id', 'delay_minutes', 'position', 'enabled'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(AlertEscalationFactory::class)]
final class AlertEscalation extends Model
{
    /** @use HasFactory<AlertEscalationFactory> */
    use HasFactory;

    /**
     * The rule whose incidents escalate.
     *
     * @return BelongsTo<AlertRule, $this>
     */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    /**
     * Who is notified at this step.
     *
     * @return BelongsTo<AlertDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['delay_minutes' => 'integer', 'position' => 'integer', 'enabled' => 'boolean'];
    }
}
