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

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dnsProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'dns_provider_id');
    }
}
