<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site's scheduled report or traffic spike alert, sent to an email address or a Slack incoming webhook.
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $created_by
 * @property string $kind one of KINDS
 * @property string $channel email or slack
 * @property string $target the email address or Slack webhook address (encrypted)
 * @property int|null $threshold for spike alerts, how many current visitors count as a spike
 * @property string|null $last_period the week (2026-W39), month (2026-09) or spike day last sent, so each goes once
 * @property Carbon|null $last_sent_at
 * @property string|null $last_error why the last send failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
final class AnalyticsNotification extends Model
{
    /**
     * The kinds, with their labels.
     *
     * @var array<string, string>
     */
    public const KINDS = ['weekly' => 'Weekly report', 'monthly' => 'Monthly report', 'spike' => 'Traffic spike alert'];

    /**
     * The channels, with their labels.
     *
     * @var array<string, string>
     */
    public const CHANNELS = ['email' => 'Email', 'slack' => 'Slack'];

    /**
     * How long after a spike alert another can be sent, in hours.
     *
     * @var int
     */
    public const SPIKE_COOLDOWN_HOURS = 3;

    /**
     * The attributes that can't be mass assigned: all of them; notifications are written with forceFill.
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
        return ['target' => 'encrypted', 'threshold' => 'integer', 'last_sent_at' => 'datetime'];
    }

    /**
     * Get the site the notification is for.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * Describe where it goes without showing a Slack webhook's secret path.
     *
     * @return string
     */
    public function destination(): string
    {
        return $this->channel === 'slack' ? __('Slack') : $this->target;
    }
}
