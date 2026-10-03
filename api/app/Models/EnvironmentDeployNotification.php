<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An alert destination that hears about an environment's deploys: when one goes live, fails, or waits for approval.
 *
 * @property int $id
 * @property string $environment_id
 * @property int $alert_destination_id
 * @property bool $on_success
 * @property bool $on_failure
 * @property bool $on_approval
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AlertDestination $destination
 */
class EnvironmentDeployNotification extends Model
{
    /**
     * Get the destination the notifications go to.
     *
     * @return BelongsTo<AlertDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the three outcomes as booleans.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['on_success' => 'boolean', 'on_failure' => 'boolean', 'on_approval' => 'boolean'];
    }
}
