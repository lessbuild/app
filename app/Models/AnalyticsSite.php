<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnalyticsSiteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A website tracked by a project's Analytics service. `public_id` is the tracker's data-site value.
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $environment_id
 * @property string $name
 * @property string $public_id
 * @property list<string> $domains
 * @property list<string>|null $excluded_paths
 * @property string $timezone
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $last_processed_at
 * @property bool $collection_enabled
 * @property Carbon|null $collection_paused_at
 * @property-read Project $project
 */
#[UseFactory(AnalyticsSiteFactory::class)]
class AnalyticsSite extends Model
{
    /** @use HasFactory<AnalyticsSiteFactory> */
    use HasFactory;

    protected $table = 'analytics_sites';

    /** @var list<string> */
    protected $fillable = ['name', 'domains', 'excluded_paths', 'timezone', 'collection_enabled', 'collection_paused_at', 'last_event_at', 'last_processed_at'];

    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            $site->public_id ??= Str::lower(Str::random(24));
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'excluded_paths' => 'array',
            'verified_at' => 'datetime',
            'collection_paused_at' => 'datetime',
            'last_event_at' => 'datetime',
            'last_processed_at' => 'datetime',
            'collection_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<AnalyticsEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'site_id');
    }

    /** @return HasMany<AnalyticsGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(AnalyticsGoal::class, 'site_id');
    }

    /** @return HasMany<AnalyticsGoalConversion, $this> */
    public function goalConversions(): HasMany
    {
        return $this->hasMany(AnalyticsGoalConversion::class, 'site_id');
    }

    /** @return HasMany<AnalyticsIngestionBatch, $this> */
    public function ingestionBatches(): HasMany
    {
        return $this->hasMany(AnalyticsIngestionBatch::class, 'site_id');
    }

    /** @return HasMany<AnalyticsVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(AnalyticsVisit::class, 'site_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isCollectionAvailable(): bool
    {
        return $this->collection_enabled && $this->collection_paused_at === null && $this->isVerified();
    }

    public function excludesPath(string $path): bool
    {
        return collect($this->excluded_paths ?? [])->contains(
            fn (string $pattern): bool => $pattern !== '' && Str::is($pattern, $path),
        );
    }
}
