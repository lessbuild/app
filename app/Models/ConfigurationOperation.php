<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConfigurationOperation extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'attempts' => 'integer',
        'retry_sequence' => 'integer',
        'available_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return HasOne<ConfigurationOperation, $this> */
    public function retry(): HasOne
    {
        return $this->hasOne(self::class, 'retry_of_operation_id');
    }

    /** @return BelongsTo<ConfigurationApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ConfigurationApplication::class, 'configuration_application_id');
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
