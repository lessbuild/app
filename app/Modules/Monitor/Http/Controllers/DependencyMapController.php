<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchDependencyMapRequest;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\ServiceDependencyMap;
use Illuminate\Http\Response;

class DependencyMapController extends Controller
{
    public function index(SearchDependencyMapRequest $request, CurrentWorkspace $currentWorkspace, ServiceDependencyMap $dependencyMap): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->filters();
        $environments = Environment::forWorkspace($workspace)
            ->with('application:id,name')
            ->orderBy('application_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'application_id', 'name']);
        abort_if(isset($filters['environment']) && ! $environments->contains('id', (int) $filters['environment']), 404);
        $map = $dependencyMap->forWorkspace($workspace, $filters['range'], isset($filters['environment']) ? (int) $filters['environment'] : null);
        $environmentOptions = $environments->mapWithKeys(fn (Environment $environment): array => [
            $environment->id => $environment->application->name.' / '.$environment->name,
        ])->all();

        return response()->view('monitor::dependencies.index', compact('map', 'filters', 'environmentOptions'))
            ->header('Cache-Control', 'private, no-store');
    }
}
