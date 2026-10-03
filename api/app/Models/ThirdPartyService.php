<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service a project depends on, such as GitHub or Cloudflare, with the status its public status page reports.
 *
 * @property int $id
 * @property string $project_id
 * @property string $provider a key of App\Support\Monitoring\StatusProviders, or custom
 * @property string $name
 * @property string $url the status page's address
 * @property string $indicator none (all good), minor, major, critical, maintenance or unknown
 * @property string|null $description the status page's own words, such as "Partial System Outage"
 * @property list<string>|null $affected the components that aren't operational
 * @property string|null $incident the name of the newest unresolved incident
 * @property Carbon|null $checked_at
 * @property Carbon|null $changed_at when the indicator last changed
 * @property string|null $last_error why the last check failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
final class ThirdPartyService extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; rows are written with forceFill.
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
        return ['affected' => 'array', 'checked_at' => 'datetime', 'changed_at' => 'datetime'];
    }

    /**
     * Get the project that depends on the service.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the badge tone for the status.
     *
     * @return string
     */
    public function tone(): string
    {
        return match ($this->indicator) {
            'none' => 'success',
            'minor', 'maintenance' => 'warning',
            'major', 'critical' => 'danger',
            default => 'neutral',
        };
    }

    /**
     * Describe the status in a few words.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this->indicator) {
            'none' => __('Operational'),
            'minor' => __('Minor issues'),
            'major' => __('Major outage'),
            'critical' => __('Critical outage'),
            'maintenance' => __('Maintenance'),
            default => __('Unknown'),
        };
    }
}
