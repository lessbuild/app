<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\MonitorModel;
use LogicException;

/** Immutable proof of a native lifecycle change, committed with that change. */
final class ResourceRestorationReceipt extends MonitorModel
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Restoration receipts are immutable.'));
        self::deleting(fn () => throw new LogicException('Restoration receipts are immutable.'));
    }

    protected function casts(): array
    {
        return ['expected_revision' => 'integer', 'revision' => 'integer', 'states' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
