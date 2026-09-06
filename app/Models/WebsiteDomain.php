<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteDomain extends Model
{
    public const TYPES = ['primary', 'alias', 'redirect'];

    protected $guarded = [];

    protected $hidden = ['dns_record_id', 'last_error'];

    protected $casts = [
        'is_temporary' => 'boolean',
        'certificate_expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Provider, $this> */
    public function dnsProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'dns_provider_id');
    }
}
