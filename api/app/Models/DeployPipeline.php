<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Repositories of a project deployed one after another (such as the API, then the frontend), stopping if one fails.
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $created_by
 * @property string $name
 * @property list<int> $repository_ids in deploy order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DeployPipelineRun> $runs
 */
final class DeployPipeline extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; pipelines are written with forceFill.
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
        return ['repository_ids' => 'array'];
    }

    /**
     * Get the pipeline's runs.
     *
     * @return HasMany<DeployPipelineRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(DeployPipelineRun::class, 'pipeline_id');
    }
}
