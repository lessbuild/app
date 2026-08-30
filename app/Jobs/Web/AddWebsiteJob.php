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
     */
    public Website $website;

    public ?string $attemptToken;

    /**
     * Create a new job instance.
     */
    public function __construct(Website $website)
    {
        $this->website = $website;
        $this->attemptToken = $website->provisioning_token;
    }

    /**
     * Execute the job.
     *
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

    public function failed(\Throwable $exception): void
    {
        $query = Website::query()
            ->whereKey($this->website->id)
            ->whereIn('provisioning_status', [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING]);
        $this->attemptToken === null
            ? $query->whereNull('provisioning_token')
            : $query->where('provisioning_token', $this->attemptToken);
        $query->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
