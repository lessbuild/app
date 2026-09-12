<?php

namespace App\Actions\Web;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateWebsiteAction
{
    /**
     * Apply validated website settings while preserving placement and provisioning state guarantees.
     *
     * @param  Website  $website  Website supplying its current placement and provisioning state.
     * @param  array<string, mixed>  $attributes  Validated and normalized website settings.
     *
     * @throws ValidationException If a deployment, provisioning operation, or previous placement blocks the update.
     */
    public function handle(Website $website, array $attributes): void
    {
        DB::transaction(function () use ($attributes, $website): void {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->hasActiveDeployment()) {
                throw ValidationException::withMessages([
                    'server_id' => __('Wait for the current website deployment to finish before editing this website.'),
                ]);
            }
            if (in_array($locked->provisioning_status, [Website::STATUS_QUEUED, Website::STATUS_PROVISIONING], true)) {
                throw ValidationException::withMessages([
                    'server_id' => __('Wait for the current website provisioning operation to finish.'),
                ]);
            }

            $moving = (int) $attributes['server_id'] !== (int) $locked->server_id;
            $healthSettingsChanged = ! $attributes['health_check_enabled']
                || ! $locked->health_check_enabled
                || $attributes['health_check_path'] !== $locked->health_check_path
                || $attributes['url'] !== $locked->url
                || $moving;
            if ($healthSettingsChanged) {
                $attributes = array_merge($attributes, [
                    'health_status' => Website::HEALTH_UNKNOWN,
                    'health_failure_count' => 0,
                    'health_last_checked_at' => null,
                    'health_last_error' => null,
                ]);
            }
            if ($moving && $locked->previous_server_id) {
                throw ValidationException::withMessages([
                    'server_id' => __('Finish cleaning up the previous server before moving this website again.'),
                ]);
            }

            $requiresProvisioning = $locked->provisioning_status === Website::STATUS_FAILED
                || $moving
                || $attributes['url'] !== $locked->url
                || $attributes['environment'] !== $locked->environment;
            if (! $requiresProvisioning) {
                $locked->update($attributes);

                return;
            }

            $locked->update(array_merge($attributes, [
                'previous_server_id' => $moving ? $locked->server_id : $locked->previous_server_id,
                'placement_cleanup_error' => $moving ? null : $locked->placement_cleanup_error,
                'provisioning_token' => (string) Str::uuid(),
                'setup_stage' => 0,
                'provisioning_status' => Website::STATUS_QUEUED,
                'provisioning_error' => null,
                'provisioned_at' => null,
            ]));
            $locked->logs()->where('type', Website::PROVISIONING_LOG_TYPE)->delete();

            AddWebsiteJob::dispatch($locked)->afterCommit();
        });
    }
}
