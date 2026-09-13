<?php

namespace App\Models;

use App\Data\ObservabilityContextFilters;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservabilityInvestigationView extends Model
{
    public const EXPIRY_DAYS = [7, 30, 90];

    public const DEFAULT_EXPIRY_DAYS = 30;

    public const MAX_ACTIVE_PER_ORGANIZATION = 50;

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'expires_at' => 'datetime',
    ];

    /** Use the random UUID in shared links instead of an enumerable database ID. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Return whether the named view is no longer available for opening. */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at === null || $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    /**
     * Parse the persisted filter contract without accepting arbitrary query data.
     *
     * @return ObservabilityContextFilters|null The finite filters, or null for a corrupt legacy row.
     */
    public function contextFilters(): ?ObservabilityContextFilters
    {
        return is_array($this->filters)
            ? ObservabilityContextFilters::fromQueryParameters($this->filters)
            : null;
    }
}
