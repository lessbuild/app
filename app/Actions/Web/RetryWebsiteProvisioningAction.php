<?php

namespace App\Actions\Web;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetryWebsiteProvisioningAction
{
    /**
     * Require a server ready for websites, rotate the attempt token, reset provisioning output, and dispatch the new attempt after commit.
     *
     * Atomically queue a new attempt when the website is still failed.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     * @return bool Whether the website was still failed and a new provisioning attempt was queued.
     *
     * @throws ValidationException If the assigned server is not ready to provision websites.
     */
    public function handle(Website $website): bool
    {
        return DB::transaction(function () use ($website): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);

            if ($locked->provisioning_status !== Website::STATUS_FAILED) {
                return false;
            }

            if (! Server::readyForWebsites()->whereKey($locked->server_id)->exists()) {
                throw ValidationException::withMessages([
                    'retry' => __('The target server must be active and ready before provisioning can be retried.'),
                ]);
            }

            $locked->update([
                'provisioning_token' => (string) Str::uuid(),
                'setup_stage' => 0,
                'provisioning_status' => Website::STATUS_QUEUED,
                'provisioning_error' => null,
                'provisioned_at' => null,
            ]);
            $locked->logs()->where('type', Website::PROVISIONING_LOG_TYPE)->delete();

            AddWebsiteJob::dispatch($locked)->afterCommit();

            return true;
        });
    }
}
