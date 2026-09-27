<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A saved Monitoring dashboard. It belongs to the account, so its widgets cover every project.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string|null $description
 * @property string $range
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User|null $creator
 * @property-read Collection<int, DashboardWidget> $widgets
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(DashboardFactory::class)]
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory;

    public const WIDGETS = [
        'telemetry' => 'Telemetry summary',
        'event_mix' => 'Event mix',
        'incidents' => 'Open incidents',
        'monitors' => 'Monitor health',
        'objectives' => 'SLO health',
        'projects' => 'Projects',
    ];

    /**
     * The account the dashboard belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Who created it (`created_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The widgets on it, in display order.
     *
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }
}
