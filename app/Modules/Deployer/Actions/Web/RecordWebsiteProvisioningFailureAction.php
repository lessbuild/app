<?php

namespace App\Modules\Deployer\Actions\Web;

use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\PreviewDeploymentLifecycle;
use App\Modules\Deployer\Services\ProvisioningCallbackValidator;
use App\Modules\Deployer\Services\WebsiteProvisioningCallbackGuard;
use Illuminate\Support\Facades\DB;

class RecordWebsiteProvisioningFailureAction
{
    public function __construct(
        private readonly PreviewDeploymentLifecycle $previews,
        private readonly ProvisioningCallbackValidator $validator,
        private readonly WebsiteProvisioningCallbackGuard $guard,
    ) {}

    /**
     * Record a current website provisioning failure and reconcile its preview.
     *
     * @param  Website  $website  Website receiving the signed failure callback.
     * @param  mixed  $attempt  Raw attempt token checked against the locked row.
     * @param  mixed  $exitCode  Raw optional remote process exit code.
     * @param  mixed  $message  Raw remote failure message.
     * @return void Stale or terminal callbacks are acknowledged as no-ops.
     */
    public function handle(Website $website, mixed $attempt, mixed $exitCode, mixed $message): void
    {
        DB::connection('deployer')->transaction(function () use ($website, $attempt, $exitCode, $message): void {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if (! $this->guard->acceptsLifecycle($locked, $attempt)) {
                return;
            }

            $failure = $this->validator->failure($exitCode, $message);
            $locked->update([
                'provisioning_status' => Website::STATUS_FAILED,
                'provisioning_error' => $failure->formattedMessage(),
            ]);
            $this->previews->websiteFailed($locked->fresh());
        }, 5);
    }
}
