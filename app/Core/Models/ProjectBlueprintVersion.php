<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectBlueprintVersion extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Published blueprint versions are immutable. Create another version.');
        });
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ProjectBlueprint::class, 'project_blueprint_id');
    }
}
