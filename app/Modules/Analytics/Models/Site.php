<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Site extends AnalyticsModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'slug', 'public_id', 'domains', 'excluded_paths', 'timezone',
        'verification_token', 'verified_at', 'collection_enabled', 'collection_paused_at', 'last_event_at', 'last_processed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            $site->public_id ??= Str::lower(Str::random(24));
            $site->verification_token ??= Str::random(48);
            $site->slug ??= Str::slug($site->name);
        });
    }

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

    /** @return BelongsTo<Workspace> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<AnalyticsEvent> */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    /** @return HasMany<SiteReleaseAnnotation, $this> */
    public function releaseAnnotations(): HasMany
    {
        return $this->hasMany(SiteReleaseAnnotation::class)->orderByDesc('deployed_at');
    }

    /** @return HasMany<SiteIncidentAnnotation, $this> */
    public function incidentAnnotations(): HasMany
    {
        return $this->hasMany(SiteIncidentAnnotation::class)->orderByDesc('occurred_at');
    }

    /** @return HasMany<Goal> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<GoalConversion> */
    public function goalConversions(): HasMany
    {
        return $this->hasMany(GoalConversion::class);
    }

    /** @return HasMany<IngestionBatch> */
    public function ingestionBatches(): HasMany
    {
        return $this->hasMany(IngestionBatch::class);
    }

    /** @return HasMany<Visit> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
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

    public function verificationRecordName(): string
    {
        return '_buildpusher-analytics.'.($this->domains[0] ?? '');
    }

    public function hasVerificationRecord(string $token): bool
    {
        if (($this->domains ?? []) === [] || ! function_exists('dns_get_record')) {
            return false;
        }

        return collect((array) $this->domains)->contains(function (string $domain) use ($token): bool {
            try {
                $records = dns_get_record('_buildpusher-analytics.'.$domain, DNS_TXT) ?: [];
            } catch (\Throwable) {
                return false;
            }

            return collect($records)->contains(function (array $record) use ($token): bool {
                $entries = is_array($record['entries'] ?? null) ? $record['entries'] : [$record['txt'] ?? null];

                return collect($entries)->contains(fn (?string $value): bool => $value !== null && hash_equals($this->verification_token, trim($value, ' \\"')) && hash_equals($this->verification_token, trim($token)));
            });
        });
    }
}
