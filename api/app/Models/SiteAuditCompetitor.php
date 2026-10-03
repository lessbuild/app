<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A competitor's site an audit compares with: added by the customer, or suggested and waiting to be confirmed.
 *
 * @property int $id
 * @property int $site_audit_id
 * @property string $name
 * @property string $url
 * @property string $source customer or suggested
 * @property string $status confirmed, suggested or dismissed
 * @property string|null $reason why it was suggested
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiteAudit $audit
 */
final class SiteAuditCompetitor extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the audit the competitor belongs to.
     *
     * @return BelongsTo<SiteAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(SiteAudit::class, 'site_audit_id');
    }
}
