<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\InspectServerImport;
use App\Http\Requests\Infrastructure\ServerImportRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/** Runs the read-only inspection; the confirmation token rides in the session to the review page. */
final class InspectServerImportController
{
    /**
     * Inspects the server and shows what was found.
     */
    public function __invoke(ServerImportRequest $request, #[CurrentUser] User $user, Project $project, InspectServerImport $inspect): RedirectResponse
    {
        [$assessment, $token] = $inspect->handle($project->account, $user, $request->import());
        $request->session()->put("server_import.{$assessment->id}", $token);

        return to_route('infrastructure.imports.show', [$project, $assessment->id]);
    }
}
