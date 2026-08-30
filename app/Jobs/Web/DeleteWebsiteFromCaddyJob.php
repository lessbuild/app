<?php

namespace App\Jobs\Web;

use App\Actions\Web\DeleteWebsiteFromCaddyAction;
use App\Models\Repository;
use App\Models\Website;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeleteWebsiteFromCaddyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $websiteId;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(int $websiteId)
    {
        $this->websiteId = $websiteId;
    }

    /**
     * Execute the job.
     *
     *
     * @throws \Exception
     */
    public function handle(Runner $runner): void
    {
        $website = Website::withTrashed()->find($this->websiteId);
        if (! $website) {
            return;
        }

        (new DeleteWebsiteFromCaddyAction($website, $runner))->handle();

        DB::transaction(function () use ($website): void {
            Repository::withTrashed()
                ->where('website_id', $website->id)
                ->each(function (Repository $repository): void {
                    $repository->builds()->delete();
                    $repository->forceDelete();
                });

            Website::withoutEvents(fn () => $website->forceDelete());
        });
    }

    public function failed(\Throwable $exception): void
    {
        $website = Website::withTrashed()->find($this->websiteId);
        if (! $website) {
            return;
        }

        $website->restore();
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
