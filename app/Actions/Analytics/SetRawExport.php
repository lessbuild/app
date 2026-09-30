<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SetRawExport
{
    /**
     * Start exporting a site's raw events each day to one of its project's storage buckets (from yesterday on), or
     * stop when no bucket is given.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  int|null  $bucketId
     * @param  string|null  $prefix  a folder inside the bucket
     * @return void
     */
    public function handle(User $actor, AnalyticsSite $site, ?int $bucketId, ?string $prefix): void
    {
        Gate::forUser($actor)->authorize('update', $site);
        if ($bucketId === null) {
            $site->forceFill(['export_bucket_id' => null, 'export_prefix' => null, 'exported_until' => null, 'export_error' => null])->save();

            return;
        }
        $bucket = StorageBucket::query()->where('project_id', $site->project_id)->find($bucketId);
        if ($bucket === null) {
            throw new AccountRuleViolation('export_bucket_id', __('Choose one of this project’s storage buckets.'));
        }
        $prefix = trim((string) $prefix, ' /');
        if ($prefix !== '' && preg_match('~\A[A-Za-z0-9._/-]{1,200}\z~', $prefix) !== 1) {
            throw new AccountRuleViolation('export_prefix', __('Use letters, numbers, dots, dashes, underscores and slashes.'));
        }
        $changed = $site->export_bucket_id !== $bucket->id;
        $site->forceFill([
            'export_bucket_id' => $bucket->id, 'export_prefix' => $prefix === '' ? null : $prefix, 'export_error' => null,
            'exported_until' => $changed ? null : $site->exported_until,
        ])->save();
    }
}
