<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a document's names to records in the project's account: placements to websites, deploy repositories to
 * repositories of the project on that website, and secret references to secret variables (and their current version).
 */
final class ConfigurationBindings
{
    /**
     * Resolves the document's placements, repositories and secrets to records the person may use in the project's
     * account, checking each fits (a repository on the placement's website, a secret in a compatible scope). Any problem
     * is one generic error, so bindings can't be used to probe other records.
     *
     * @param  Project  $project
     * @param  User  $user
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $bindings
     * @return array{placements: array<string, array{website_id: int, resource_fingerprint?: string}>, secrets: array<string, array{variable_id: int, version: int}>, repositories: array<string, array{repository_id: int, fingerprint: string}>}
     */
    public function resolve(Project $project, User $user, array $document, array $bindings): array
    {
        if (array_diff(array_keys($bindings), ['placements', 'secrets', 'repositories']) !== []) {
            $this->invalid();
        }
        foreach ($bindings as $entries) {
            if (! is_array($entries) || count($entries) > 2000) {
                $this->invalid();
            }
            foreach ($entries as $name => $id) {
                if (! is_string($name) || strlen($name) > 100 || preg_match('/\A[a-z][a-z0-9_-]*\z/', $name) !== 1 || ! is_int($id) || $id < 1) {
                    $this->invalid();
                }
            }
        }
        $resolved = ['placements' => [], 'secrets' => [], 'repositories' => []];
        foreach ($document['environments'] as $environment) {
            $name = $environment['placement'];
            $website = Website::query()->where('account_id', $project->account_id)->with('server')->find($this->id($bindings, 'placements', $name));
            if ($website === null || $website->server === null || ! $user->can('view', $website)) {
                $this->invalid();
            }
            $resolved['placements'][$name] ??= ['website_id' => $website->id];
            if (collect((array) ($environment['resources'] ?? []))->contains(fn (array $resource): bool => $resource['managed'] && in_array($resource['type'], ['mysql', 'postgresql'], true))) {
                $resolved['placements'][$name]['resource_fingerprint'] = hash_hmac('sha256', json_encode([$website->getRawOriginal('database_password'), $website->databaseIdentifier()], JSON_THROW_ON_ERROR), (string) config('app.key'));
            }
            if (isset($environment['deploy'])) {
                $reference = $environment['deploy']['repository'];
                $repository = Repository::query()->where('project_id', $project->id)->where('website_id', $website->id)->find($this->id($bindings, 'repositories', $reference));
                if ($repository === null || ! $user->can('deploy', $repository) || ! $repository->isDeploymentReady()) {
                    $this->invalid();
                }
                $resolved['repositories'][$reference] = ['repository_id' => $repository->id, 'fingerprint' => ConfigurationIdentity::repository($repository)];
            }
            $variables = array_values($environment['variables'] ?? []);
            foreach ($environment['resources'] ?? [] as $resource) {
                foreach ($resource['variable_refs'] ?? [] as $reference) {
                    $variables[] = ['secret_ref' => $reference, 'scope' => 'runtime'];
                }
            }
            foreach ($variables as $variable) {
                $reference = $variable['secret_ref'];
                $secret = EnvironmentVariable::query()->where('is_secret', true)->with('environment.project')
                    ->whereHas('environment.project', fn ($query) => $query->where('account_id', $project->account_id))->find($this->id($bindings, 'secrets', $reference));
                if ($secret === null || ! $user->can('configureDeploy', $secret->environment) || ($secret->scope !== 'all' && $secret->scope !== $variable['scope'])) {
                    $this->invalid();
                }
                $resolved['secrets'][$reference] = ['variable_id' => $secret->id, 'version' => $secret->current_version];
            }
        }

        return $resolved;
    }

    /**
     * The ID bound to a name, or 0 when there's none.
     *
     * @param  array<string, mixed>  $bindings
     * @param  string  $kind
     * @param  string  $name
     * @return int
     */
    private function id(array $bindings, string $kind, string $name): int
    {
        $entries = $bindings[$kind] ?? [];
        $id = is_array($entries) ? ($entries[$name] ?? null) : null;

        return is_int($id) ? $id : 0;
    }

    /**
     * Refuses the bindings without saying which one failed.
     *
     * @return never
     */
    private function invalid(): never
    {
        throw ValidationException::withMessages(['bindings' => 'A required binding is unavailable or incompatible in this account.']);
    }
}
