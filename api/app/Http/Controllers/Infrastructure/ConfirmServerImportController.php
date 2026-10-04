<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ConfirmServerImport;
use App\Models\Project;
use App\Models\ServerImportAssessment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfirmServerImportController
{
    /**
     * Import the inspected server, using the confirmation token this browser was given when it inspected it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $assessment
     * @param  ConfirmServerImport  $confirm
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $assessment, ConfirmServerImport $confirm): JsonResponse
    {
        $record = ServerImportAssessment::query()->where('account_id', $project->account_id)->findOrFail((int) $assessment);
        $token = $request->session()->pull("server_import.{$record->id}");
        $server = $confirm->handle($project->account, $user, $record, is_string($token) ? $token : '');

        // The passwords are shown once, on the server's page.
        return response()->json([
            'redirect' => route('infrastructure.servers.show', [$project, $server->id], false),
            'message' => __('Server imported. Provisioning is running over SSH.'),
            'secrets' => array_filter(['root' => $server->provisioningRootPassword(), 'mysql' => $server->mysql_root_password]),
        ]);
    }
}
