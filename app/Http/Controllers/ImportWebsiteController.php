<?php

namespace App\Http\Controllers;

use App\Actions\Web\ImportWebsiteAction;
use App\Http\Requests\ImportWebsiteRequest;
use App\Models\Server;
use App\Services\PlanLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImportWebsiteController extends Controller
{
    /**
     * Render active workspace servers and the website allowance for importing an existing application.
     */
    public function create(Request $request, PlanLimits $limits): View
    {
        return view('scenes.websites.import', [
            'servers' => $request->user()->workspaceServers()->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get(),
            'planUsage' => $limits->usage($request->user(), 'websites'),
        ]);
    }

    /**
     * Verify the validated application directory on an active workspace server and create an imported website.
     *
     * @return RedirectResponse The imported website page; an unreadable directory raises a validation error.
     */
    public function store(ImportWebsiteRequest $request, ImportWebsiteAction $importWebsite): RedirectResponse
    {
        $server = $request->user()->workspaceServers()->where('provisioning_status', Server::STATUS_ACTIVE)->findOrFail($request->integer('server_id'));
        $website = $importWebsite->handle($request->user(), $server, $request->validated());

        return redirect()->route('websites.show', $website)->with('success', __('Application imported. Connect its repository when you are ready to deploy a new release.'));
    }
}
