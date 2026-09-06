<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Services\Entitlements;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $request->mergeIfMissing([
            'minimum_replicas' => 1,
            'maximum_replicas' => 1,
            'runtime_type' => 'php',
            'runtime_version' => null,
            'build_command' => null,
            'start_command' => null,
            'container_port' => null,
            'dockerfile_path' => null,
        ]);
        $data = $this->validated($request, $project);
        $this->enforceRuntimeFeatures($project, $data);
        if (($data['is_protected'] || $data['requires_deployment_approval']) && ! $project->organization->permits($request->user(), 'manage')) {
            abort(403);
        }
        if ($data['type'] === 'production' && $project->environments()->where('type', 'production')->exists()) {
            return back()->withErrors(['type' => __('This application already has a production environment.')])->withInput();
        }
        $base = Str::slug($data['name']) ?: 'environment';
        $slug = $base;
        $suffix = 2;
        while ($project->environments()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $project->environments()->create([...$data, 'slug' => $slug]);

        return back()->with('success', __('Environment created.'));
    }

    public function update(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $request->mergeIfMissing([
            'minimum_replicas' => $environment->minimum_replicas,
            'maximum_replicas' => $environment->maximum_replicas,
            'hibernate_after_minutes' => $environment->hibernate_after_minutes,
            'runtime_type' => $environment->runtime_type ?: 'php',
            'runtime_version' => $environment->runtime_version,
            'build_command' => $environment->build_command,
            'start_command' => $environment->start_command,
            'container_port' => $environment->container_port,
            'dockerfile_path' => $environment->dockerfile_path,
        ]);
        $data = $this->validated($request, $environment->project);
        $this->enforceRuntimeFeatures($environment->project, $data, $environment);
        if ($data['type'] === 'production' && $environment->project->environments()->where('type', 'production')->whereKeyNot($environment->id)->exists()) {
            return back()->withErrors(['type' => __('This application already has a production environment.')])->withInput();
        }
        $environment->update($data);

        return back()->with('success', __('Environment updated.'));
    }

    public function variables(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $request->mergeIfMissing(['scope' => 'runtime']);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/\A[A-Z_][A-Z0-9_]*\z/'],
            'value' => ['required', 'string', 'max:10000'],
            'is_secret' => ['nullable', 'boolean'],
            'scope' => ['required', Rule::in(EnvironmentVariable::SCOPES)],
            'rotation_due_at' => ['nullable', 'date', 'after:today'],
        ]);
        DB::transaction(function () use ($environment, $data, $request): void {
            $variable = $environment->variables()->where('key', $data['key'])->lockForUpdate()->first();
            $version = ($variable?->current_version ?? 0) + 1;
            $attributes = [
                'value' => $data['value'],
                'is_secret' => $request->boolean('is_secret', true),
                'scope' => $data['scope'],
                'current_version' => $version,
                'rotated_at' => $variable ? now() : null,
                'rotation_due_at' => $data['rotation_due_at'] ?? null,
                'updated_by' => $request->user()->id,
            ];
            if ($variable) {
                $variable->update($attributes);
            } else {
                $variable = $environment->variables()->create(['key' => $data['key'], ...$attributes]);
            }
            $variable->versions()->create([
                'created_by' => $request->user()->id,
                'version' => $version,
                'value' => $data['value'],
            ]);
        });

        return back()->with('success', __('Environment variable saved securely.'));
    }

    /** Delete the route-bound variable only from its authorized parent environment. */
    public function destroyVariable(Environment $environment, EnvironmentVariable $variable): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless((int) $variable->environment_id === (int) $environment->id, 404);
        $variable->delete();

        return back()->with('success', __('Environment variable deleted.'));
    }

    public function storeProcess(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'workers');
        $request->mergeIfMissing(['restart_policy' => 'always', 'restart_delay_seconds' => 5]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]*\z/'],
            'type' => ['required', Rule::in(EnvironmentProcess::TYPES)],
            'command' => ['required', 'string', 'max:2000'],
            'replicas' => ['required', 'integer', 'between:1,20'],
            'restart_policy' => ['required', Rule::in(['always', 'on-failure', 'no'])],
            'restart_delay_seconds' => ['required', 'integer', 'between:0,300'],
            'is_enabled' => ['required', 'boolean'],
        ]);
        if ($data['type'] === 'scheduler') {
            $data['replicas'] = 1;
        }
        $environment->processes()->updateOrCreate(['name' => $data['name']], $data);

        return back()->with('success', __('Process definition saved. It will be applied on the next deployment.'));
    }

    public function destroyProcess(Environment $environment, EnvironmentProcess $process): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless($process->environment_id === $environment->id, 404);
        $process->delete();

        return back()->with('success', __('Process removed. The next deployment will stop its service.'));
    }

    public function storeResource(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'resources');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]*\z/'],
            'type' => ['required', Rule::in(EnvironmentResource::TYPES)],
            'is_managed' => ['required', 'boolean'],
            'variables' => ['nullable', 'string', 'max:10000'],
        ]);
        if ($data['is_managed'] && $data['type'] === 'object_storage') {
            return back()->withErrors(['type' => __('Object storage must use externally supplied credentials.')])->withInput();
        }
        $variables = $this->parseVariables((string) ($data['variables'] ?? ''));
        if ($data['is_managed'] && in_array($data['type'], ['mysql', 'postgresql'], true)) {
            if (! $environment->website) {
                return back()->withErrors(['type' => __('Attach a website before adding its managed database.')])->withInput();
            }
            $postgresql = $data['type'] === 'postgresql';
            $variables = [
                'DB_CONNECTION' => $postgresql ? 'pgsql' : 'mysql',
                'DB_HOST' => $postgresql ? '127.0.0.1' : $environment->website->server->public_ip,
                'DB_PORT' => $postgresql ? '5432' : '3306',
                'DB_DATABASE' => $environment->website->databaseIdentifier(),
                'DB_USERNAME' => $environment->website->databaseIdentifier(),
                'DB_PASSWORD' => $environment->website->database_password,
            ];
        } elseif ($data['is_managed'] && $data['type'] === 'redis') {
            $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'];
        } elseif ($data['is_managed'] && $data['type'] === 'valkey') {
            $port = 16379 + ($environment->id % 10000);
            $variables = [
                'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => (string) $port,
                'VALKEY_HOST' => '127.0.0.1', 'VALKEY_PORT' => (string) $port,
            ];
        }
        $environment->resources()->updateOrCreate(['name' => $data['name']], [
            'type' => $data['type'],
            'is_managed' => $data['is_managed'],
            'configuration' => [
                'variables' => $variables,
                'container_name' => $data['is_managed'] && $data['type'] === 'valkey'
                    ? 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($data['name'])
                    : null,
            ],
            'status' => 'ready',
        ]);

        return back()->with('success', __('Resource attached. Its variables will be snapshotted into future deployments.'));
    }

    public function destroyResource(Environment $environment, EnvironmentResource $resource): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless($resource->environment_id === $environment->id, 404);
        $resource->delete();

        return back()->with('success', __('Resource detached.'));
    }

    public function updateDeploymentControls(Request $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $request->mergeIfMissing([
            'deployment_strategy' => $environment->deployment_strategy ?: 'blue_green',
            'rolling_pause_seconds' => $environment->rolling_pause_seconds ?? 2,
            'automatic_rollback' => false,
        ]);
        $data = $request->validate([
            'deployment_locked' => ['required', 'boolean'],
            'deployment_lock_reason' => ['nullable', 'string', 'max:500'],
            'deployment_window_enabled' => ['required', 'boolean'],
            'deployment_window_days' => ['nullable', 'array', 'min:1'],
            'deployment_window_days.*' => ['integer', 'between:1,7', 'distinct'],
            'deployment_window_start' => ['nullable', 'date_format:H:i'],
            'deployment_window_end' => ['nullable', 'date_format:H:i'],
            'deployment_window_timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'deployment_strategy' => ['required', Rule::in(Environment::DEPLOYMENT_STRATEGIES)],
            'rolling_pause_seconds' => ['required', 'integer', Rule::in([0, 1, 2, 5, 10, 30])],
            'automatic_rollback' => ['required', 'boolean'],
        ]);
        if ($data['deployment_window_enabled'] && (empty($data['deployment_window_days'])
            || empty($data['deployment_window_start']) || empty($data['deployment_window_end'])
            || empty($data['deployment_window_timezone']))) {
            throw ValidationException::withMessages([
                'deployment_window_days' => __('Choose days, start and end times, and a timezone for the window.'),
            ]);
        }

        $environment->update([
            'deployment_locked_at' => $data['deployment_locked'] ? ($environment->deployment_locked_at ?? now()) : null,
            'deployment_locked_by' => $data['deployment_locked'] ? $request->user()->id : null,
            'deployment_lock_reason' => $data['deployment_locked'] ? ($data['deployment_lock_reason'] ?: null) : null,
            'deployment_window_days' => $data['deployment_window_enabled'] ? array_values($data['deployment_window_days']) : null,
            'deployment_window_start' => $data['deployment_window_enabled'] ? $data['deployment_window_start'] : null,
            'deployment_window_end' => $data['deployment_window_enabled'] ? $data['deployment_window_end'] : null,
            'deployment_window_timezone' => $data['deployment_window_enabled'] ? $data['deployment_window_timezone'] : null,
            'deployment_strategy' => $data['deployment_strategy'],
            'rolling_pause_seconds' => $data['rolling_pause_seconds'],
            'automatic_rollback' => $data['automatic_rollback'],
        ]);

        return back()->with('success', __('Deployment controls updated.'));
    }

    public function destroy(Environment $environment): RedirectResponse
    {
        $this->authorize('delete', $environment);
        abort_if($environment->type === 'production', 422, 'The production environment cannot be deleted.');
        $environment->delete();

        return back()->with('success', __('Environment deleted.'));
    }

    private function validated(Request $request, Project $project): array
    {
        $organizationId = $project->organization_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(Environment::TYPES)],
            'branch' => ['required', 'string', 'max:255'],
            'runtime_type' => ['required', Rule::in(Environment::RUNTIME_TYPES)],
            'runtime_version' => ['nullable', 'string', 'max:20', 'regex:/\A[0-9]+(?:\.[0-9]+){0,2}\z/'],
            'build_command' => ['nullable', 'string', 'max:2000'],
            'start_command' => ['nullable', 'string', 'max:2000', 'required_if:runtime_type,node,python'],
            'container_port' => ['nullable', 'integer', 'between:1,65535', 'required_if:runtime_type,node,python,docker'],
            'dockerfile_path' => ['nullable', 'string', 'max:255', 'regex:/\A(?!\/)(?!.*\.\.)(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\z/', 'required_if:runtime_type,docker'],
            'server_id' => ['nullable', Rule::exists('servers', 'id')->where('organization_id', $organizationId)],
            'website_id' => ['nullable', Rule::exists('websites', 'id')->where('organization_id', $organizationId)],
            'is_protected' => ['required', 'boolean'],
            'requires_deployment_approval' => ['required', 'boolean'],
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20', 'gte:minimum_replicas'],
            'hibernate_after_minutes' => ['nullable', 'integer', Rule::in([5, 15, 30, 60, 120, 1440])],
        ]);
    }

    private function enforceRuntimeFeatures(Project $project, array $data, ?Environment $current = null): void
    {
        $scalingChanged = ! $current
            || (int) $data['minimum_replicas'] !== $current->minimum_replicas
            || (int) $data['maximum_replicas'] !== $current->maximum_replicas;
        if ($scalingChanged && ((int) ($data['minimum_replicas'] ?? 1) !== 1 || (int) ($data['maximum_replicas'] ?? 1) !== 1)) {
            $this->entitlements->enforce($project->organization, 'scaling');
        }
        $requestedHibernation = is_null($data['hibernate_after_minutes'] ?? null) ? null : (int) $data['hibernate_after_minutes'];
        $hibernationChanged = ! $current || $requestedHibernation !== $current->hibernate_after_minutes;
        if ($hibernationChanged && ! is_null($data['hibernate_after_minutes'] ?? null)) {
            $this->entitlements->enforce($project->organization, 'hibernation');
        }
    }

    /** @return array<string, string> */
    private function parseVariables(string $input): array
    {
        $variables = [];
        foreach (preg_split('/\R/', $input) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $matches)) {
                throw ValidationException::withMessages([
                    'variables' => __('Each resource variable must use KEY=value on its own line.'),
                ]);
            }
            $variables[$matches[1]] = $matches[2];
        }

        return $variables;
    }
}
