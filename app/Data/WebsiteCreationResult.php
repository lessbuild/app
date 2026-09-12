<?php

namespace App\Data;

use App\Models\Website;

class WebsiteCreationResult
{
    /**
     * Carry the created website and its one-time plaintext database password to the HTTP session boundary.
     *
     * @param  Website  $website  Newly queued website.
     * @param  string  $databasePassword  Password shown once to the creating actor and stored encrypted by the model.
     */
    public function __construct(
        public readonly Website $website,
        public readonly string $databasePassword,
    ) {}
}
