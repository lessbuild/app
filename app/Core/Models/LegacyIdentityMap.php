<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;

class LegacyIdentityMap extends CoreModel
{
    protected $fillable = [
        'source_product',
        'source_entity',
        'source_id',
        'canonical_entity',
        'canonical_id',
        'status',
        'batch_key',
        'reconciliation_notes',
        'metadata',
        'imported_at',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'imported_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
