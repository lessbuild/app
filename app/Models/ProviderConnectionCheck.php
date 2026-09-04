<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderConnectionCheck extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTOMATIC = 'automatic';

    public const MAX_PER_PROVIDER = 100;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'successful' => 'boolean',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
