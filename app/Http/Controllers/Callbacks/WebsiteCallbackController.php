<?php

namespace App\Http\Controllers\Callbacks;

use App\Http\Controllers\Controller;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Website;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WebsiteCallbackController extends Controller
{
    /**
     * Record monotonic lifecycle progress from a signed callback.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Website  $website  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function status(Request $request, Website $website): Response
    {
        $accepted = DB::transaction(function () use ($request, $website): bool {
            $website = Website::query()->lockForUpdate()->findOrFail($website->id);
            if (! $this->acceptsLifecycleCallback($request, $website)) {
                return false;
            }

            $finalStage = app(WebsiteProvisioningPlan::class)->finalStage();
            $data = $request->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
            $data['status'] = (int) $data['status'];
            if ($data['status'] > $website->setup_stage) {
                $website->update(['setup_stage' => $data['status']]);
            }

            if ($data['status'] === $finalStage) {
                $previousServerId = $website->previous_server_id;
                $website->update([
                    'provisioning_status' => Website::STATUS_ACTIVE,
                    'provisioned_at' => now(),
                    'provisioning_error' => null,
                ]);
                app(PreviewDeploymentLifecycle::class)->websiteReady($website->fresh());

                if ($previousServerId) {
                    CleanupWebsitePlacementJob::dispatch(
                        $website->id,
                        $previousServerId,
                        $website->deployment_slug,
                    )->afterCommit();
                }
            }

            return true;
        }, 5);

        return $accepted ? response('') : response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Website  $website  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(Request $request, Website $website): Response
    {
        DB::transaction(function () use ($request, $website): void {
            $website = Website::query()->lockForUpdate()->findOrFail($website->id);
            if (! $this->acceptsLifecycleCallback($request, $website)) {
                return;
            }

            $data = $request->validate([
                'exit_code' => 'nullable|integer',
                'message' => 'required|string|max:2000',
            ]);
            $website->update([
                'provisioning_status' => Website::STATUS_FAILED,
                'provisioning_error' => isset($data['exit_code'])
                    ? "{$data['message']} (exit code {$data['exit_code']})"
                    : $data['message'],
            ]);
            app(PreviewDeploymentLifecycle::class)->websiteFailed($website->fresh());
        }, 5);

        return response()->noContent();
    }

    /**
     * Store bounded callback output without accepting stale attempts.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Website  $website  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function log(Request $request, Website $website): Response
    {
        DB::transaction(function () use ($request, $website): void {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->provisioning_token && ! hash_equals($locked->provisioning_token, (string) $request->input('attempt'))) {
                return;
            }

            $data = $request->validate([
                'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.website_log_max_characters'))],
            ]);
            $locked->logs()->updateOrCreate(
                ['type' => Website::PROVISIONING_LOG_TYPE],
                ['log' => $data['log']],
            );
        });

        return response()->noContent();
    }

    /**
     * Check current attempt identity and lifecycle state while the target row is locked.
     *
     * @param  Request  $request  The signed callback carrying the attempt token.
     * @param  Website  $website  The freshly locked record, never the stale route-binding snapshot.
     * @return bool Whether this callback can still change the lifecycle state.
     */
    private function acceptsLifecycleCallback(Request $request, Website $website): bool
    {
        return (! $website->provisioning_token || hash_equals($website->provisioning_token, (string) $request->input('attempt')))
            && in_array($website->provisioning_status, [
                Website::STATUS_QUEUED,
                Website::STATUS_PROVISIONING,
            ], true);
    }
}
