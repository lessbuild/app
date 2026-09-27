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

    /**
     * Stored in `analytics_sites`.
     */
    protected $table = 'analytics_sites';

    /**
     * The site's settings and collection state.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'domains', 'excluded_paths', 'timezone', 'collection_enabled', 'collection_paused_at', 'last_event_at', 'last_processed_at'];

    /**
     * Gives each new site a random public ID for its tracker snippet, so the internal ID isn't exposed.
     */
    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            $site->public_id ??= Str::lower(Str::random(24));
        });
    }

    /**
     * Reads `domains` and `excluded_paths` as JSON lists.
     *
     * @return array<string, string>
     */
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

    /**
     * The project the site belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Everything the site has sent.
     *
     * @return HasMany<AnalyticsEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'site_id');
    }

    /**
     * The site's goals.
     *
     * @return HasMany<AnalyticsGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(AnalyticsGoal::class, 'site_id');
    }

    /**
     * Goal completions on the site.
     *
     * @return HasMany<AnalyticsGoalConversion, $this>
     */
    public function goalConversions(): HasMany
    {
        return $this->hasMany(AnalyticsGoalConversion::class, 'site_id');
    }

    /**
     * Batches the site has sent.
     *
     * @return HasMany<AnalyticsIngestionBatch, $this>
     */
    public function ingestionBatches(): HasMany
    {
        return $this->hasMany(AnalyticsIngestionBatch::class, 'site_id');
    }

    /**
     * Visits reconstructed from the site's events.
     *
     * @return HasMany<AnalyticsVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(AnalyticsVisit::class, 'site_id');
    }

    /**
     * Whether one of the site's hostnames was matched to a verified domain of its project, proving the project controls
     * the website.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether events are accepted: collection is on, not paused, and the site is verified.
     */
    public function isCollectionAvailable(): bool
    {
        return $this->collection_enabled && $this->collection_paused_at === null && $this->isVerified();
    }

    /**
     * Whether a page path matches one of the site's excluded patterns (such as `/admin/*`) and shouldn't be recorded.
     */
    public function excludesPath(string $path): bool
    {
        return collect($this->excluded_paths ?? [])->contains(
            fn (string $pattern): bool => $pattern !== '' && Str::is($pattern, $path),
        );
    }
}
