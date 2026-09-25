<?php

namespace App\Modules\Analytics\Services\Deletion;

use Illuminate\Support\Facades\DB;

final class AnalyticsDeletionFence
{
    public function assertWorkspaceOpen(string|int $workspaceId): void
    {
        abort_if($this->isFenced('workspace', (string) $workspaceId), 409, 'This Analytics workspace is being deleted.');
    }

    public function assertAccountOpen(string|int $userId): void
    {
        abort_if($this->isFenced('account', (string) $userId), 409, 'This Analytics account is being deleted.');
    }

    public function assertCanonicalAccountOpen(string $canonicalId): void
    {
        abort_if(DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', 'account')->where('canonical_id', $canonicalId)->exists(),
            409,
            'This Analytics account is being deleted.');
    }

    public function assertCanonicalWorkspaceOpen(string $canonicalId): void
    {
        abort_if(DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', 'workspace')->where('canonical_id', $canonicalId)->exists(),
            409,
            'This Analytics workspace is being deleted.');
    }

    public function isFenced(string $kind, string $sourceId): bool
    {
        return DB::connection('analytics')->table('analytics_deletion_tombstones')
            ->where('kind', $kind)
            ->where('source_id', $sourceId)
            ->exists();
    }
}
