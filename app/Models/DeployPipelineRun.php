<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a deploy pipeline: which step it's on, the deploy started for each step, and how it ended.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string|null $started_by
 * @property string $status running, succeeded or failed
 * @property int $step the index of the repository being deployed
 * @property list<int> $build_ids the deploy of each step so far
 * @property string|null $failure why it stopped
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DeployPipeline $pipeline
 * @property-read User|null $starter
 */
final class DeployPipelineRun extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; runs are written with forceFill.
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
        return ['build_ids' => 'array', 'step' => 'integer', 'finished_at' => 'datetime'];
    }

    /**
     * Get the pipeline the run belongs to.
     *
     * @return BelongsTo<DeployPipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(DeployPipeline::class, 'pipeline_id');
    }

    /**
     * Get the person who started the run; later steps deploy as them.
     *
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
