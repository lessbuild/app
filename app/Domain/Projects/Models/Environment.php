<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Projects\Enums\EnvironmentKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property-read Project $project
 */
class Environment extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => EnvironmentKind::class];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
