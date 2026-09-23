<?php

namespace App\Modules\Monitor\Models;

use Carbon\CarbonImmutable;
use App\Modules\Monitor\Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'created_by', 'name', 'reason', 'starts_at', 'ends_at'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    /** @param Builder<MaintenanceWindow> $query */
    #[Scope]
    protected function activeAt(Builder $query, Workspace $workspace, CarbonImmutable $at): void
    {
        $query->whereBelongsTo($workspace)->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
