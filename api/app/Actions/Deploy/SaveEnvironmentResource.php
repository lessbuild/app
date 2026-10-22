<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
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
     * Add or change a resource. Managed MySQL uses the chosen website's database (the environment's own website with a
     * database when none is chosen); managed Redis and Valkey run on the server; anything else is external and described
     * by pasted KEY=value variables. Its variables go into `.env` on deploy.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{name: string, type: string, is_managed: bool, variables: string|null, database_website_id?: int|string|null}  $data
     * @return EnvironmentResource
     */
    public function handle(User $actor, Environment $environment, array $data): EnvironmentResource
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if (! $this->entitlements->for($environment->project->account)->has('deploy.resources')) {
            throw ValidationException::withMessages(['resource' => __('Resources come with the Pro Deploy plan and above.')]);
        }
        $variables = [];
        $databaseWebsite = null;
        if ($data['is_managed']) {
            $website = $data['type'] === 'mysql' ? $databaseWebsite = $this->databaseWebsite($environment, $data['database_website_id'] ?? null) : null;
            $variables = match ($data['type']) {
                'mysql' => $website === null ? throw ValidationException::withMessages(['type' => __('Connect a repository that deploys this environment to a website with a database first.')]) : [
                    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $website->databaseIdentifier(),
                    'DB_USERNAME' => $website->databaseIdentifier(), 'DB_PASSWORD' => (string) $website->database_password,
                ],
                'redis', 'valkey' => EnvironmentResource::managedCache($environment->id, $data['type'], $data['name'])['variables'],
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
            'configuration' => ['variables' => $variables, 'container_name' => $data['is_managed'] && $data['type'] === 'valkey' ? EnvironmentResource::managedCache($environment->id, 'valkey', $data['name'])['container_name'] : null, 'database_website_id' => $databaseWebsite?->id],
        ])->save();

        return $resource;
    }

    /**
     * Find the website whose database a managed MySQL resource uses: the one chosen, which must belong to the account
     * and have a database, or else the first of the environment's own websites that has one. Null when there's none.
     *
     * @param  Environment  $environment
     * @param  int|string|null  $chosen  a website ID
     * @return Website|null
     */
    private function databaseWebsite(Environment $environment, int|string|null $chosen): ?Website
    {
        if ($chosen !== null && $chosen !== '') {
            $website = Website::query()->whereKey((int) $chosen)->where('account_id', $environment->project->account_id)->where('has_database', true)->first();

            return $website ?? throw ValidationException::withMessages(['database_website_id' => __('Choose one of your websites that has a database.')]);
        }

        return Repository::query()->where('environment_id', $environment->id)->with('website')->get()
            ->pluck('website')->filter()->first(fn (Website $website): bool => $website->has_database);
    }
}
