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
 * @property list<int>|null $together the steps (by index) that start together with the one before, side by side
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
        return ['repository_ids' => 'array', 'together' => 'array'];
    }

    /**
     * Split the steps into the groups that deploy side by side, in order: each group starts once every deploy in the
     * one before it is live.
     *
     * @return list<list<int>> repository IDs per group
     */
    public function groups(): array
    {
        $groups = [];
        $current = [];
        foreach ($this->repository_ids as $index => $repositoryId) {
            if ($current !== [] && ! in_array($index, $this->together ?? [], true)) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = (int) $repositoryId;
        }

        return $current === [] ? $groups : [...$groups, $current];
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
