<?php

namespace App\Http\Controllers;

use App\Jobs\Web\AddWebsiteJob;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $websites = $request->user()->websites()->latest()->paginate();

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
        $repositories = $website->repositories()->latest()->paginate();

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
            'server_id' => 'required|exists:servers,id',
            'url' => 'required|max:255',
            'description' => 'required',
            'environment' => 'required',
        ]);

        $website = $request->user()->websites()->create($validated);

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
        $validated = $this->validate($request, [
            'name' => 'required|max:255',
            'url' => 'required|max:255',
            'description' => 'required',
            'environment' => 'present',
        ]);

        $website->update(array_merge($validated, [
            'setup_stage' => 0
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
        $website->delete();

        return redirect()->route('websites.index');
    }
}
