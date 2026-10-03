<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The latest copy of one of a website's logs (provisioning, and later access and runtime logs).
 *
 * @property int $id
 * @property int $website_id
 * @property string $type
 * @property string|null $log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class WebsiteLog extends Model
{
    /**
     * Only the key is guarded: logs are written by jobs, never from request input.
     *
     * @var array<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the website the log is about.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
