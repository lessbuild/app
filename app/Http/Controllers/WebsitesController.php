<?php

namespace App\Http\Controllers;

use App\Actions\Web\RetryWebsiteProvisioningAction;
use App\Http\Requests\WebsiteRequest;
use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsitesController extends Controller
{
    /**
     * List all created websites for the user
     */
    public function index(Request $request): View
    {
        $websites = $request->user()->websites()->with('server')->latest()->paginate();

        return view('scenes.websites.index', [
            'websites' => $websites,
        ]);
    }

    /**
     * Show the specified websites
     */
    public function show(Website $website): View
    {
        $this->authorize('view', $website);

        $repositories = $website->repositories()->with('latestBuild')->latest()->paginate();

        return view('scenes.websites.show', [
            'website' => $website,
            'repositories' => $repositories,
        ]);
    }

    /**
     * Show the resource creation form
     */
    public function create(Request $request): View
    {
        $servers = $request->user()->servers()->readyForWebsites()->get();

        return view('scenes.websites.create', [
            'servers' => $servers,
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function store(WebsiteRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $password = Str::random(32);
        $website = $request->user()->websites()->create(array_merge($validated, [
            'database_password' => $password,
            'provisioning_status' => Website::STATUS_QUEUED,
        ]));
        session()->flash("website:{$website->id}:mysql_password", $password);
        AddWebsiteJob::dispatch($website);

        return redirect()->route('websites.show', $website);
    }

    /**
     * Show the resource edit form
     */
    public function edit(Request $request, Website $website): View
    {
        $this->authorize('update', $website);

        $servers = $request->user()->servers()->readyForWebsites()->get();

        return view('scenes.websites.edit', [
            'servers' => $servers,
            'website' => $website,
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function update(WebsiteRequest $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);
        $validated = $request->validated();
        DB::transaction(function () use ($validated, $website): void {
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

            $moving = (int) $validated['server_id'] !== (int) $locked->server_id;
            $healthSettingsChanged = ! $validated['health_check_enabled']
                || ! $locked->health_check_enabled
                || $validated['health_check_path'] !== $locked->health_check_path
                || $validated['url'] !== $locked->url
                || $moving;
            if ($healthSettingsChanged) {
                $validated = array_merge($validated, [
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
                || $validated['url'] !== $locked->url
                || $validated['environment'] !== $locked->environment;
            if (! $requiresProvisioning) {
                $locked->update($validated);

                return;
            }

            $locked->update(array_merge($validated, [
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

        return redirect()->route('websites.show', $website);
    }

    public function retryPlacementCleanup(Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        if (! $website->previous_server_id) {
            return back()->with('info', __('There is no previous website placement to clean up.'));
        }

        $website->update(['placement_cleanup_error' => null]);
        CleanupWebsitePlacementJob::dispatch(
            $website->id,
            $website->previous_server_id,
            $website->deployment_slug,
        );

        return back()->with('success', __('Previous server cleanup queued.'));
    }

    public function retryProvisioning(
        Website $website,
        RetryWebsiteProvisioningAction $retry,
    ): RedirectResponse {
        $this->authorize('update', $website);

        if (! $retry->handle($website)) {
            return back()->with('info', __('Website provisioning is no longer in a failed state.'));
        }

        return back()->with('success', __('Website provisioning retry queued.'));
    }

    /**
     * Remove the specified resource from storage
     *
     * @return RedirectResponse
     */
    public function destroy(Website $website)
    {
        $this->authorize('delete', $website);

        $deleted = DB::transaction(function () use ($website): bool {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->hasActiveDeployment()) {
                return false;
            }

            return (bool) $locked->delete();
        });

        if (! $deleted) {
            return back()->with('error', __('Wait for the current deployment to finish before deleting this website.'));
        }

        return redirect()
            ->route('websites.index')
            ->with('success', __('Website deletion queued.'));
    }
}
