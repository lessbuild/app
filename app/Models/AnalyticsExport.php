<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string|null $requested_by
 * @property string $token_hash
 * @property string $status
 * @property array<string, mixed>|null $filters
 * @property string|null $file_path
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property-read AnalyticsSite $site
 */
class AnalyticsExport extends Model
{
    /**
     * Stored in `analytics_exports`.
     */
    protected $table = 'analytics_exports';

    /**
     * Written by the export request and the job that builds the file.
     *
     * @var list<string>
     */
    protected $fillable = ['site_id', 'requested_by', 'token_hash', 'status', 'filters', 'file_path', 'expires_at', 'completed_at', 'failure_message'];

    /**
     * Reads `filters` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * The site being exported.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }

    /**
     * Who asked for the export (`requested_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
