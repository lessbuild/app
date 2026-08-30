<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BuildsController extends Controller
{
    /**
     * Show resources in storage
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
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
}
