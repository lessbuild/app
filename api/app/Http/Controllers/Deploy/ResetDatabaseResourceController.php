<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\DatabaseCommands;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetDatabaseResourceController
{
    /**
     * Empty a managed database (drop every table) so the next deploy's migrations run from scratch — for recovering a
     * database a failed first deploy left half-migrated. Destructive: it keeps whoever can manage the environment's
     * resources, and the page confirms before calling it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  int  $resource
     * @param  ServerShell  $shell
     * @param  DatabaseCommands  $commands
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, int $resource, ServerShell $shell, DatabaseCommands $commands): JsonResponse
    {
        Gate::forUser($user)->authorize('configureDeploy', $environment);
        $managed = EnvironmentResource::query()->where('environment_id', $environment->id)->where('is_managed', true)->findOrFail($resource);
        if (! in_array($managed->type, ['mysql', 'postgresql'], true)) {
            throw ValidationException::withMessages(['resource' => __('Only a managed database can be reset.')]);
        }

        if ($managed->type === 'mysql') {
            $website = Website::query()->find((int) ($managed->configuration['database_website_id'] ?? 0));
            if ($website?->server === null) {
                throw ValidationException::withMessages(['resource' => __('This database isn’t on a server yet.')]);
            }
            [$server, $command, $name] = [$website->server, $commands->reset($website), $website->databaseIdentifier()];
        } else {
            $variables = $managed->configuration['variables'] ?? [];
            $website = Website::query()->where('environment_id', $environment->id)->whereNotNull('server_id')->with('server')->first();
            if ($website?->server === null) {
                throw ValidationException::withMessages(['resource' => __('This database isn’t on a server yet.')]);
            }
            [$server, $command, $name] = [$website->server, $commands->resetPostgres((string) ($variables['DB_DATABASE'] ?? ''), (string) ($variables['DB_USERNAME'] ?? '')), (string) ($variables['DB_DATABASE'] ?? '')];
        }

        $result = $shell->run($server, $command, timeout: 300);
        if (! $result->successful()) {
            throw ValidationException::withMessages(['resource' => __('The database couldn’t be reset: :reason', ['reason' => Str::limit(trim($result->errorOutput ?: $result->output) ?: 'the server command failed', 300)])]);
        }

        return response()->json(['message' => __('Emptied :database. Deploy again to run its migrations from scratch.', ['database' => $name])]);
    }
}
