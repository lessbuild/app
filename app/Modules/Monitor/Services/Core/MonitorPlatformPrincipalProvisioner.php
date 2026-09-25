<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Monitor\Models\User;

final class MonitorPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'monitor';
    }
}
