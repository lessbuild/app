<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone outside the account with view-only access to one site's report, through a personal link that can be
 * revoked on its own.
 *
 * @property int $id
 * @property int $site_id
 * @property string $email
 * @property string $token_hash SHA-256 of their link's token
 * @property string|null $invited_by
 * @property Carbon|null $last_viewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AnalyticsSite $site
 */
final class AnalyticsSiteViewer extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; viewers are written with forceFill.
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
        return ['last_viewed_at' => 'datetime'];
    }

    /**
     * Get the site they can view.
     *
     * @return BelongsTo<AnalyticsSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(AnalyticsSite::class, 'site_id');
    }
}
