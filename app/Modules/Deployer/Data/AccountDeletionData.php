<?php

namespace App\Modules\Deployer\Data;

class AccountDeletionData
{
    /** Carry the optional validated two-factor challenge to the account deletion operation. */
    public function __construct(public readonly ?string $twoFactorCode = null) {}
}
