<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\DatabaseUser;

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
