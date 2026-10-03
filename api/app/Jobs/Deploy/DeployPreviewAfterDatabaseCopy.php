<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Models\Preview;
use App\Services\Deploy\Previews;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class DeployPreviewAfterDatabaseCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new DeployPreviewAfterDatabaseCopy instance.
     *
     * Runs after a new preview's database was copied, so its first deploy (and migrations) use the copy.
     *
     * @param  int  $previewId  The preview.
     */
    public function __construct(public readonly int $previewId) {}

    /**
     * Deploy the preview now that its database is in place.
     *
     * @param  Previews  $previews
     * @return void
     */
    public function handle(Previews $previews): void
    {
        $preview = Preview::query()->with('website')->find($this->previewId);
        if ($preview?->website !== null) {
            $previews->websiteReady($preview->website);
        }
    }
}
