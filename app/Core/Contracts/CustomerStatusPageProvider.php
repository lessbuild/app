<?php

namespace App\Core\Contracts;

use App\Core\Data\Status\CustomerStatusPage;

interface CustomerStatusPageProvider
{
    public function findPublished(string $slug): ?CustomerStatusPage;

    /** Create or replace a pending subscriber after the caller validates the email. */
    public function subscribe(string $slug, string $email): bool;
}
