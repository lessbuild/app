<?php

namespace App\Modules\Monitor\Services\Core;

use Illuminate\Support\Facades\DB;

final class MonitorDeletionFence
{
    public static function workspaceIsFenced(string|int|null $workspaceId): bool
    {
        return $workspaceId !== null && DB::connection('monitor')->table('product_deletion_fences')
            ->where('kind', 'workspace')->where('source_id', (string) $workspaceId)->exists();
    }

    public static function lockWorkspace(string|int|null $workspaceId): bool
    {
        if ($workspaceId === null) {
            return false;
        }

        DB::connection('monitor')->table('workspaces')->where('id', (string) $workspaceId)
            ->update(['id' => DB::raw('id')]);

        return self::workspaceIsFenced($workspaceId);
    }

    public static function userIsFenced(string|int|null $userId): bool
    {
        return $userId !== null && DB::connection('monitor')->table('product_deletion_fences')
            ->where('kind', 'account')->where('source_id', (string) $userId)->exists();
    }

    public static function lockUser(string|int|null $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        DB::connection('monitor')->table('users')->where('id', (string) $userId)
            ->update(['id' => DB::raw('id')]);

        return self::userIsFenced($userId);
    }

    public static function canonicalAccountIsFenced(string|int $canonicalId): bool
    {
        return DB::connection('monitor')->table('product_deletion_fences')
            ->where('kind', 'account')->get(['target'])->contains(function (object $fence) use ($canonicalId): bool {
                $target = json_decode($fence->target, true);

                return is_array($target) && (string) ($target['canonicalId'] ?? '') === (string) $canonicalId;
            });
    }

    public static function canonicalWorkspaceIsFenced(string|int $canonicalId): bool
    {
        return DB::connection('monitor')->table('product_deletion_fences')
            ->where('kind', 'workspace')->get(['target'])->contains(function (object $fence) use ($canonicalId): bool {
                $target = json_decode($fence->target, true);

                return is_array($target) && (string) ($target['canonicalId'] ?? '') === (string) $canonicalId;
            });
    }

    public static function assertWorkspaceActive(string|int|null $workspaceId): void
    {
        abort_if(self::workspaceIsFenced($workspaceId), 410, 'This Monitor workspace is being deleted.');
    }

    public static function assertUserActive(string|int|null $userId): void
    {
        abort_if(self::userIsFenced($userId), 410, 'This Monitor account is being deleted.');
    }
}
