<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\StatusPageFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'slug', 'description', 'published'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    use HasProjectVisibility;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<StatusPageComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
