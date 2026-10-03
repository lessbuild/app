<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Models\Website;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class WebsiteProvisioned
{
    use Dispatchable;

    /**
     * Create a new WebsiteProvisioned instance.
     *
     * A website's setup script finished, so it can take deploys. Fired after the change is committed.
     *
     * @param  Website  $website  The website, now active.
     */
    public function __construct(public Website $website) {}
}
