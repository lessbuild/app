<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StatusPageComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One monitor shown on a status page, under a public label.
 *
 * @property int $id
 * @property int $status_page_id
 * @property int $monitor_id
 * @property string $label
 * @property int $position
 * @property string|null $group_name the heading it's shown under, such as API or Website
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StatusPage $statusPage
 * @property-read Monitor|null $monitor
 */
#[Fillable(['monitor_id', 'label', 'position', 'group_name'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(StatusPageComponentFactory::class)]
class StatusPageComponent extends Model
{
    /** @use HasFactory<StatusPageComponentFactory> */
    use HasFactory;

    /**
     * Get the page the component is on.
     *
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * Get the monitor it shows, including archived ones (which are then left off the page).
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
