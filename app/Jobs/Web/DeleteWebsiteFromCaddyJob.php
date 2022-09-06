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

    /**
     * The website instance.
     *
     * @var \App\Models\Website
     */
    public Website $website;

    /**
     * Create a new job instance.
     *
     * @param array $website
     */
    public function __construct(array $website)
    {
        $this->website = Website::make($website);
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
        (new DeleteWebsiteFromCaddyAction($this->website))->handle();
    }
}
