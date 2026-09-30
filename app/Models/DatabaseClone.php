<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Copying one website's database over another's on the same server (say production into staging).
 *
 * @property int $id
 * @property int $source_website_id
 * @property int $target_website_id
 * @property string|null $requested_by
 * @property string $status queued, running, succeeded or failed
 * @property string $mode full, sample (the first rows of each table) or schema
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $source
 * @property-read Website $target
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DatabaseClone extends Model
{
    /**
     * Get the website whose database is copied, including deleted ones so history reads.
     *
     * @return BelongsTo<Website, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'source_website_id')->withTrashed();
    }

    /**
     * Get the website whose database is overwritten.
     *
     * @return BelongsTo<Website, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'target_website_id')->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
