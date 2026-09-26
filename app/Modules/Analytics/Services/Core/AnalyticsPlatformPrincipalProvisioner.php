<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Illuminate\Support\Facades\DB;

final class AnalyticsPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    public function provision(PlatformUser $platformUser): string
    {
        return DB::connection('analytics')->transaction(function () use ($platformUser): string {
            app(AnalyticsDeletionFence::class)->assertCanonicalAccountOpen((string) $platformUser->getAuthIdentifier());

            return parent::provision($platformUser);
        }, attempts: 3);
    }

    public function synchronizeMappedPrincipal(string $sourceId, PlatformUser $platformUser): bool
    {
        app(AnalyticsDeletionFence::class)->assertCanonicalAccountOpen((string) $platformUser->getAuthIdentifier());
        app(AnalyticsDeletionFence::class)->assertAccountOpen($sourceId);

        return parent::synchronizeMappedPrincipal($sourceId, $platformUser);
    }

    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'analytics';
    }
}
