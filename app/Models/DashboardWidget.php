<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dashboard_id
 * @property string $type
 * @property int $position
 * @property array<string, mixed>|null $configuration
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Dashboard $dashboard
 */
#[Fillable(['type', 'position', 'configuration'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DashboardWidget extends Model
{
    /**
     * The dashboard the widget is on.
     *
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * Reads `configuration` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer', 'configuration' => 'array'];
    }
}
