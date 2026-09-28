<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Repository;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveEnvironmentResource
{
    /**
     * Create a new SaveEnvironmentResource instance.
     *
     * Attaches or changes an environment's resource, within the plan.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes managed resources.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Add or change a resource. Managed MySQL uses the website's own database; managed Redis and Valkey run on the server;
     * anything else is external and described by pasted KEY=value variables. Its variables go into `.env` on deploy.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, type: string, is_managed: bool, variables: string|null}  $data
     * @return EnvironmentResource
     */
    public function handle(User $actor, Environment $environment, array $data): EnvironmentResource
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.resources')) {
            throw ValidationException::withMessages(['resource' => __('Resources come with the Pro Deploy plan and above.')]);
        }
        $variables = [];
        if ($data['is_managed']) {
            $website = Repository::query()->where('environment_id', $environment->id)->with('website.server')->first()?->website;
            $variables = match ($data['type']) {
                'mysql' => $website === null ? throw ValidationException::withMessages(['type' => __('Connect a repository that deploys this environment to a website first.')]) : [
                    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $website->databaseIdentifier(),
                    'DB_USERNAME' => $website->databaseIdentifier(), 'DB_PASSWORD' => (string) $website->database_password,
                ],
                'redis' => ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'],
                'valkey' => ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => (string) (16379 + crc32($environment->id) % 10000), 'VALKEY_HOST' => '127.0.0.1', 'VALKEY_PORT' => (string) (16379 + crc32($environment->id) % 10000)],
                default => throw ValidationException::withMessages(['type' => __('Only MySQL, Redis and Valkey can be managed; describe other services with their variables.')]),
            };
        } else {
            foreach (preg_split('/\R/', (string) $data['variables']) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $match) !== 1) {
                    throw ValidationException::withMessages(['variables' => __('Each variable must be KEY=value on its own line.')]);
                }
                $variables[$match[1]] = $match[2];
            }
        }
        $resource = $environment->resources()->where('name', $data['name'])->first() ?? new EnvironmentResource;
        $resource->forceFill([
            'environment_id' => $environment->id, 'name' => $data['name'], 'type' => $data['type'], 'is_managed' => $data['is_managed'], 'status' => 'ready',
            'configuration' => ['variables' => $variables, 'container_name' => $data['is_managed'] && $data['type'] === 'valkey' ? 'buildpusher-valkey-'.strtolower($environment->id).'-'.str($data['name'])->slug() : null],
        ])->save();

        return $resource;
    }
}
