<?php

namespace App\Http\Controllers;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsitesController extends Controller
{
    /**
     * List all created websites for the user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
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
     *
     * @param  \App\Models\Website  $website
     * @return \Illuminate\Contracts\View\View
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
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function create(Request $request): View
    {
        $servers = $request->user()->servers()->get();

        return view('scenes.websites.create', [
            'servers' => $servers,
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validate($request, [
            'name' => 'required|max:255',
            'server_id' => [
                'required',
                Rule::exists('servers', 'id')->where('user_id', $request->user()->id),
            ],
            'url' => 'required|max:255',
            'description' => 'required',
            'environment' => 'required',
        ]);

        $password = Str::random(32);
        $website = $request->user()->websites()->create(array_merge($validated, [
            'database_password' => $password,
            'provisioning_status' => Website::STATUS_QUEUED,
        ]));
        session()->flash($website->name.'_mysql_password', $password);
        AddWebsiteJob::dispatch($website);

        return redirect()->route('websites.show', $website);
    }

    /**
     * Show the resource edit form
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Website  $website
     * @return \Illuminate\Contracts\View\View
     */
    public function edit(Request $request, Website $website): View
    {
        $this->authorize('update', $website);

        $servers = $request->user()->servers()->get();

        return view('scenes.websites.edit', [
            'servers' => $servers,
            'website' => $website,
        ]);
    }

    /**
     * Store a newly created resource in storage
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Website  $website
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorize('update', $website);

        $validated = $this->validate($request, [
            'name' => 'required|max:255',
            'url' => 'required|max:255',
            'description' => 'required',
            'environment' => 'present',
        ]);

        $website->update(array_merge($validated, [
            'setup_stage' => 0,
            'provisioning_status' => Website::STATUS_QUEUED,
            'provisioning_error' => null,
            'provisioned_at' => null,
        ]));

        AddWebsiteJob::dispatch($website);

        return redirect()->route('websites.show', $website);
    }

    /**
     * Remove the specified resource from storage
     *
     * @param  \App\Models\Website  $website
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Website $website)
    {
        $this->authorize('delete', $website);

        $website->delete();

        return redirect()->route('websites.index');
    }
}
