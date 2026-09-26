<?php

namespace App\Core\Contracts;

use App\Core\Models\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;

interface ProductPrincipalAdapter
{
    public function resolve(PlatformUser $user): ?Authenticatable;
}
