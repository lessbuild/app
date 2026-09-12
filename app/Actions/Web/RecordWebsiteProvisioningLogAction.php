<?php

namespace App\Actions\Web;

use App\Models\Website;
use App\Services\ProvisioningCallbackValidator;
use App\Services\WebsiteProvisioningCallbackGuard;
use Illuminate\Support\Facades\DB;

class RecordWebsiteProvisioningLogAction
{
    public function __construct(
        private readonly ProvisioningCallbackValidator $validator,
        private readonly WebsiteProvisioningCallbackGuard $guard,
    ) {}

    /**
     * Record bounded website provisioning output for the current attempt.
     *
     * Validation deliberately occurs after the row lock and attempt check;
     * tokenless legacy callbacks and post-completion logs retain their behavior.
     *
     * @param  Website  $website  Website receiving the signed log callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $log  Raw log input validated only after attempt acceptance.
     * @return void Stale callbacks are acknowledged as no-ops.
     */
    public function handle(Website $website, mixed $attempt, mixed $log): void
    {
        DB::transaction(function () use ($website, $attempt, $log): void {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if (! $this->guard->matchesAttempt($locked, $attempt)) {
                return;
            }

            $validatedLog = $this->validator->log(
                $log,
                (int) config('lessbuild.website_log_max_characters'),
            );
            $locked->logs()->updateOrCreate(
                ['type' => Website::PROVISIONING_LOG_TYPE],
                ['log' => $validatedLog],
            );
        });
    }
}
