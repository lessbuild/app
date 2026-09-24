<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\AbstractProductPrincipalProvisioner;
use App\Modules\Deployer\Models\User;

final class DeployerPlatformPrincipalProvisioner extends AbstractProductPrincipalProvisioner
{
    protected function userModel(): string
    {
        return User::class;
    }

    protected function product(): string
    {
        return 'deployer';
    }

    protected function additionalAttributes(PlatformUser $platformUser): array
    {
        return [
            'auth_type' => 'platform',
            'password_set_at' => $platformUser->password_set_at,
        ];
    }
}
