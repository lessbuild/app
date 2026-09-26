<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Arr;

final class SiteDeletionOperation extends AnalyticsModel
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        self::updating(function (self $operation): void {
            foreach ([
                'site_source_id', 'workspace_source_id', 'requester_source_id',
                'canonical_workspace_id', 'canonical_requester_id', 'payload_hash',
            ] as $field) {
                if ($operation->isDirty($field)) {
                    throw new \LogicException('Accepted Analytics site deletion bindings are immutable.');
                }
            }

            if ($operation->getOriginal('status') === 'completed' && $operation->isDirty('status')) {
                throw new \LogicException('A completed Analytics site deletion cannot be reopened.');
            }

            $previousManifest = $operation->getOriginal('file_manifest') ?? [];
            $nextManifest = $operation->file_manifest ?? [];
            if (array_diff(Arr::wrap($previousManifest), Arr::wrap($nextManifest)) !== []) {
                throw new \LogicException('An accepted Analytics site deletion manifest cannot discard file paths.');
            }

            if ($operation->getOriginal('manifest_at') !== null && $operation->isDirty('file_manifest')) {
                throw new \LogicException('An Analytics site deletion manifest cannot change after cleanup starts.');
            }
        });
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_manifest' => 'array',
            'manifest_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
