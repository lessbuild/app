<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Models\Website;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class WebsiteProvisioningFailed
{
    use Dispatchable;

    /**
     * Create a new WebsiteProvisioningFailed instance.
     *
     * A website's setup script failed. Fired after the change is committed.
     *
     * @param  Website  $website  The website, now failed.
     */
    public function __construct(public Website $website) {}
}
