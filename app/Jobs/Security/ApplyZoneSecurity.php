<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Models\SecurityZone;
use App\Services\Security\CloudflareZoneSecurity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class ApplyZoneSecurity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two tries, for a passing Cloudflare hiccup.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 20;

    /**
     * Create a new ApplyZoneSecurity instance.
     *
     * Pushes a zone's security settings to Cloudflare.
     *
     * @param  int  $zoneId  The SecurityZone row.
     */
    public function __construct(public readonly int $zoneId) {}

    /**
     * Apply the settings.
     *
     * @param  CloudflareZoneSecurity  $cloudflare
     * @return void
     */
    public function handle(CloudflareZoneSecurity $cloudflare): void
    {
        $zone = SecurityZone::query()->with('provider')->find($this->zoneId);
        if ($zone !== null) {
            $cloudflare->apply($zone);
        }
    }

    /**
     * Record why Cloudflare refused.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        SecurityZone::query()->whereKey($this->zoneId)->update(['last_error' => str($exception->getMessage())->limit(500)->toString()]);
    }
}
