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

    public function __construct(public readonly int $websiteId) {}

    public function handle(WebsiteHealthMonitor $monitor): void
    {
        $website = Website::with('server')->find($this->websiteId);
        if ($website) {
            $monitor->check($website);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }
}
