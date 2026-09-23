<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Http\Request;

final class CurrentWorkspace
{
    public function __construct(private readonly Request $request) {}

    public function get(): Workspace
    {
        $user = $this->request->user();
        abort_unless($user, 401);

        $application = $this->request->route('application');
        $environment = $this->request->route('environment');

        if (! $application instanceof Application && $environment instanceof Environment) {
            $application = $environment->application;
        }

        if ($application instanceof Application) {
            $workspace = $user->workspaces()->whereKey($application->workspace_id)->first();
            abort_unless($workspace, 404);

            return $workspace;
        }

        $workspace = $user->workspaces()
            ->whereKey($this->request->session()->get('workspace_id'))
            ->first();

        if ($workspace === null) {
            $workspace = $user->workspaces()->orderBy('workspaces.id')->first();
        }

        abort_unless($workspace, 403, 'Create a workspace to continue.');
        $this->request->session()->put('workspace_id', $workspace->id);

        return $workspace;
    }
}
