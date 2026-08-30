<?php

namespace App\Http\Controllers;

use App\Models\Build;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BuildsController extends Controller
{
    /**
     * Show resources in storage
     */
    public function index(Request $request): View
    {
        $builds = $request->user()
            ->builds()
            ->with('repository.website.server')
            ->latest('builds.created_at')
            ->simplePaginate();

        return view('scenes.builds.index', [
            'builds' => $builds,
        ]);
    }

    public function show(Build $build): View
    {
        $this->authorize('view', $build);
        $build->load(['repository.website.server', 'logs']);

        return view('scenes.builds.show', [
            'build' => $build,
            'deploymentLog' => $build->logs->firstWhere('type', 'deployment'),
        ]);
    }
}
