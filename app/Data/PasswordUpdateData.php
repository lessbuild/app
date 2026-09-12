<?php

namespace App\Data;

class PasswordUpdateData
{
    /** Carry the validated replacement password to the account operation. */
    public function __construct(public readonly string $password) {}
}
