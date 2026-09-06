<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportWebsiteRequest;
use App\Models\Server;
use App\Models\Website;
use App\Services\ActivityRecorder;
use App\Services\PlanLimits;
use App\Services\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportWebsiteController extends Controller
{
    public function create(Request $request, PlanLimits $limits): View
    {
        return view('scenes.websites.import', [
            'servers' => $request->user()->workspaceServers()->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get(),
            'planUsage' => $limits->usage($request->user(), 'websites'),
        ]);
    }

    public function store(ImportWebsiteRequest $request, PlanLimits $limits, Runner $runner, ActivityRecorder $activity): RedirectResponse
    {
        $server = $request->user()->workspaceServers()->where('provisioning_status', Server::STATUS_ACTIVE)->findOrFail($request->integer('server_id'));
        $root = '/var/www/'.$request->validated('deployment_slug');
        $probe = $runner->server($server)->create()->execute('test -d '.escapeshellarg($root).' && test -r '.escapeshellarg($root));
        if (! $probe->isSuccessful()) {
            throw ValidationException::withMessages(['deployment_slug' => __('That readable application directory was not found under /var/www on this server.')]);
        }

        $website = $limits->withinLimit($request->user(), 'websites', fn ($organization) => $organization->websites()->create([
            'user_id' => $request->user()->id,
            'server_id' => $server->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'url' => $request->validated('url'),
            'deployment_slug' => $request->validated('deployment_slug'),
            'environment' => '',
            'database_password' => Str::password(32, symbols: false),
            'setup_stage' => 0,
            'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => now(),
            'health_check_enabled' => false,
            'health_status' => Website::HEALTH_UNKNOWN,
        ]));
        $activity->record($website, $request->user()->id, 'website', 'Existing application imported without modifying its files or proxy configuration.');

        return redirect()->route('websites.show', $website)->with('success', __('Application imported. Connect its repository when you are ready to deploy a new release.'));
    }
}
