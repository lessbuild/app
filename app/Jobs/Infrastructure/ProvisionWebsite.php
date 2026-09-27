<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Website;
use App\Services\Infrastructure\WebsiteProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Starts a website's setup script on its server; the script reports progress back through the callbacks. */
final class ProvisionWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Starting website provisioning can fail on a busy server, so it gets three tries.
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     */
    public int $backoff = 10;

    /**
     * Creates a website's directories, database and Caddy site on its server.
     *
     * @param  int  $websiteId  The website.
     * @param  string  $attempt  The provisioning attempt's token, so a job from an earlier attempt does nothing.
     */
    public function __construct(public readonly int $websiteId, public readonly string $attempt) {}

    /**
     * Claims the attempt and starts provisioning on the server.
     */
    public function handle(WebsiteProvisioner $provisioner): void
    {
        if ($this->attempt()->where('provisioning_status', Website::STATUS_QUEUED)->update(['provisioning_status' => Website::STATUS_PROVISIONING, 'provisioning_error' => null]) === 0) {
            return;
        }
        $provisioner->start($this->attempt()->with('server')->firstOrFail());
    }

    /**
     * Marks the website failed and stores the error as its provisioning log.
     */
    public function failed(Throwable $exception): void
    {
        $website = $this->attempt()->whereIn('provisioning_status', [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING])->first();
        if ($website !== null) {
            $message = Str::limit($exception->getMessage(), 2000);
            $website->forceFill(['provisioning_status' => Website::STATUS_FAILED, 'provisioning_error' => $message])->save();
            $website->logs()->updateOrCreate(['type' => 'provisioning'], ['log' => $message]);
        }
    }

    /**
     * The website, only while it's still on this provisioning attempt.
     *
     * @return Builder<Website>
     */
    private function attempt(): Builder
    {
        return Website::query()->whereKey($this->websiteId)->where('provisioning_token', $this->attempt);
    }
}
