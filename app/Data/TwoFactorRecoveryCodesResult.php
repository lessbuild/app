<?php

namespace App\Data;

class TwoFactorRecoveryCodesResult
{
    /**
     * Carry replacement plaintext recovery codes to the one-time HTTP session flash.
     *
     * @param  list<string>  $recoveryCodes  Codes whose hashes replace the persisted list.
     */
    public function __construct(public readonly array $recoveryCodes) {}
}
