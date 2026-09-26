<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Monitor\Models\User;
use Illuminate\Support\Facades\DB;

final class MonitorPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    public function synchronizeMappedPrincipal(string $sourceId, PlatformUser $platformUser): bool
    {
        return DB::connection('monitor')->transaction(function () use ($sourceId, $platformUser): bool {
            DB::connection('monitor')->table('users')->where('id', $sourceId)->update(['id' => DB::raw('id')]);
            MonitorDeletionFence::assertUserActive($sourceId);
            abort_if(MonitorDeletionFence::canonicalAccountIsFenced($platformUser->getKey()), 410, 'This Monitor account is being deleted.');

            return parent::synchronizeMappedPrincipal($sourceId, $platformUser);
        }, attempts: 3);
    }

    public function provision(PlatformUser $platformUser): string
    {
        return DB::connection('monitor')->transaction(function () use ($platformUser): string {
            $sourceId = DB::connection('monitor')->table('users')->where('platform_user_id', $platformUser->getKey())->value('id');
            if ($sourceId !== null) {
                DB::connection('monitor')->table('users')->where('id', $sourceId)->update(['id' => DB::raw('id')]);
                MonitorDeletionFence::assertUserActive($sourceId);
            }
            abort_if(MonitorDeletionFence::canonicalAccountIsFenced($platformUser->getKey()), 410, 'This Monitor account is being deleted.');

            return parent::provision($platformUser);
        }, attempts: 3);
    }

    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'monitor';
    }
}
