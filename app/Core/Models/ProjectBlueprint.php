<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBlueprint extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['latest_version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectBlueprintVersion::class)->orderByDesc('version');
    }
}
