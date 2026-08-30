<?php

namespace App\Jobs\Web;

use App\Actions\Web\AddWebsiteAction;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AddWebsiteJob implements ShouldQueue
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
     * @param  \App\Models\Website  $website
     */
    public function __construct(Website $website)
    {
        $this->website = $website;
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
        $this->website->update([
            'provisioning_status' => Website::STATUS_PROVISIONING,
            'provisioning_error' => null,
        ]);

        (new AddWebsiteAction($this->website))->handle();
    }

    public function failed(\Throwable $exception): void
    {
        $this->website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
