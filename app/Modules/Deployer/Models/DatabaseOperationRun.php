<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DatabaseOperationRun extends DeployerModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'subject_id' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'lease_expires_at' => 'datetime',
    ];

    /** @return BelongsTo<EnvironmentResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'environment_resource_id');
    }

    public function claim(): bool
    {
        return DB::connection('deployer')->transaction(function (): bool {
            $operationRun = self::query()->lockForUpdate()->find($this->getKey());

            if ($operationRun === null
                || in_array($operationRun->status, [self::SUCCEEDED, self::FAILED], true)
                || ($operationRun->status === self::RUNNING && $operationRun->lease_expires_at?->isFuture())) {
                return false;
            }

            $operationRun->update([
                'status' => self::RUNNING,
                'attempts' => $operationRun->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'lease_expires_at' => now()->addMinutes(30),
            ]);
            $this->refresh();

            return true;
        });
    }

    public function queueForRetry(): void
    {
        $this->update([
            'status' => self::QUEUED,
            'finished_at' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markSucceeded(): void
    {
        $this->update([
            'status' => self::SUCCEEDED,
            'finished_at' => now(),
            'lease_expires_at' => null,
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => self::FAILED,
            'finished_at' => now(),
            'lease_expires_at' => null,
        ]);
    }
}
