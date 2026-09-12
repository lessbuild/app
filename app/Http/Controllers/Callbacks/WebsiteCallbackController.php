<?php

namespace App\Http\Controllers\Callbacks;

use App\Actions\Web\RecordWebsiteProvisioningFailureAction;
use App\Actions\Web\RecordWebsiteProvisioningStatusAction;
use App\Http\Controllers\Controller;
use App\Models\Website;
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
    public function status(
        Request $request,
        Website $website,
        RecordWebsiteProvisioningStatusAction $record,
    ): Response {
        $accepted = $record->handle(
            $website,
            $request->input('attempt'),
            $request->input('status'),
        );

        return $accepted ? response('') : response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  Request  $request  Signed callback input, validated after lifecycle acceptance.
     * @param  Website  $website  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(
        Request $request,
        Website $website,
        RecordWebsiteProvisioningFailureAction $record,
    ): Response {
        $record->handle(
            $website,
            $request->input('attempt'),
            $request->input('exit_code'),
            $request->input('message'),
        );

        return response()->noContent();
    }

    /**
     * Store bounded callback output without accepting stale attempts.
     *
     * @param  Request  $request  Signed callback input, validated after lifecycle acceptance.
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
}
