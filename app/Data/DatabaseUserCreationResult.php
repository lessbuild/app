<?php

namespace App\Data;

use App\Models\DatabaseUser;

class DatabaseUserCreationResult
{
    /**
     * Carry the newly persisted credential and its one-time plaintext password to the HTTP response.
     */
    public function __construct(
        public readonly DatabaseUser $user,
        public readonly string $password,
    ) {}
}
