<?php

namespace App\Jobs\Web;

use App\Actions\Web\DeleteWebsiteFromCaddyAction;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteWebsiteFromCaddyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $websiteData;

    /**
     * Create a new job instance.
     */
    public function __construct(array $website)
    {
        $this->websiteData = $website;
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handle()
    {
        // Rebuild from the snapshot because the original database row has
        // already been deleted by the time this queued job runs.
        (new DeleteWebsiteFromCaddyAction(Website::make($this->websiteData)))->handle();
    }
}
