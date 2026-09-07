<?php

namespace App\Jobs\Web;

use App\Models\Website;
use App\Services\WebsiteHealthMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckWebsiteHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 240;

    /**
     * Capture the website whose current health should be checked by the queue worker.
     *
     * @param  int  $websiteId  Website identifier retained for lookup when the job runs.
     */
    public function __construct(public readonly int $websiteId) {}

    /**
     * Run the website health monitor when the website still exists.
     *
     * @param  WebsiteHealthMonitor  $monitor  Website health checker that persists the current HTTP health outcome.
     */
    public function handle(WebsiteHealthMonitor $monitor): void
    {
        $website = Website::with('server')->find($this->websiteId);
        if ($website) {
            $monitor->check($website);
        }
    }

    /**
     * Coalesce queued instances of this job for the same website.
     *
     * @return string The website identifier used by Laravel's unique-job lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }
}
