<?php

namespace App\Data;

use App\Models\WebsiteDomain;

class WebsiteDomainSaveResult
{
    /**
     * Carry a saved domain and an optional manual-DNS or synchronization warning to the HTTP boundary.
     */
    public function __construct(
        public readonly WebsiteDomain $domain,
        public readonly ?string $warning = null,
    ) {}
}
