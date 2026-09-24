<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Analytics\Models\User;

final class AnalyticsPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'analytics';
    }
}
