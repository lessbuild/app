<?php

namespace App\Jobs\Web;

use App\Actions\Web\AddWebsiteAction;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AddWebsiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The website instance.
     */
    public Website $website;

    public ?string $attemptToken;

    /**
     * Capture the website and provisioning token so stale queued jobs cannot claim a later retry.
     *
     * Create a new job instance.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     */
    public function __construct(Website $website)
    {
        $this->website = $website;
        $this->attemptToken = $website->provisioning_token;
    }

    /**
     * Claim the matching queued website attempt and execute its provisioning action; skip attempts whose token or provisioning status has changed.
     *
     * Execute the job.
     *
     * @throws \Exception
     */
    public function handle(): void
    {
        $query = Website::query()
            ->whereKey($this->website->id)
            ->where('provisioning_status', Website::STATUS_QUEUED);
        $this->attemptToken === null
            ? $query->whereNull('provisioning_token')
            : $query->where('provisioning_token', $this->attemptToken);

        if ($query->update([
            'provisioning_status' => Website::STATUS_PROVISIONING,
            'provisioning_error' => null,
        ]) === 0) {
            return;
        }

        $this->website->refresh();
        (new AddWebsiteAction($this->website))->handle();
    }

    /**
     * Under a lock, mark only the captured active website attempt failed and persist its bounded provisioning log.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $query = Website::query()
                ->whereKey($this->website->id)
                ->whereIn('provisioning_status', [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING]);
            $this->attemptToken === null
                ? $query->whereNull('provisioning_token')
                : $query->where('provisioning_token', $this->attemptToken);
            $website = $query->lockForUpdate()->first();
            if (! $website) {
                return;
            }

            $message = str($exception->getMessage())->limit(2000)->toString();
            $website->update([
                'provisioning_status' => Website::STATUS_FAILED,
                'provisioning_error' => $message,
            ]);
            $website->logs()->updateOrCreate(
                ['type' => Website::PROVISIONING_LOG_TYPE],
                ['log' => $message],
            );
        });
    }
}
