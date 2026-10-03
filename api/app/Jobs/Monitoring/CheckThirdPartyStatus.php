<?php

declare(strict_types=1);

namespace App\Jobs\Monitoring;

use App\Services\Monitoring\ThirdPartyStatusChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class CheckThirdPartyStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new CheckThirdPartyStatus instance.
     *
     * @param  string  $url  The status page to check.
     */
    public function __construct(public string $url) {}

    /**
     * Check the status page now, so a newly followed service shows its status straight away.
     *
     * @param  ThirdPartyStatusChecker  $checker
     * @return void
     */
    public function handle(ThirdPartyStatusChecker $checker): void
    {
        $checker->check($this->url);
    }
}
