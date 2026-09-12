<?php

namespace App\Data;

class TwoFactorConfirmationResult
{
    /**
     * Carry the newly generated plaintext recovery codes to the one-time HTTP session flash.
     *
     * @param  list<string>  $recoveryCodes  Codes whose hashes are persisted on the account.
     */
    public function __construct(public readonly array $recoveryCodes) {}
}
