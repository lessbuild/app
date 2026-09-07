<?php

namespace App\Services;

use App\Models\EnvironmentVariable;
use App\Models\PreviewDeployment;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationBindings
{
    /**
     * Resolve logical names to authorized workspace targets and current secret/repository fingerprints.
     *
     * @param  Project  $project  The configuration target and workspace boundary.
     * @param  User  $user  The member who must currently have manage access in that workspace.
     * @param  array<string, mixed>  $document  A parsed version-2 document declaring placements and references.
     * @param  array<string, array<string, int>>  $bindings  Logical placement, secret and repository names mapped to stored IDs.
     * @return array{placements: array<string, array<string, mixed>>, secrets: array<string, array{variable_id: int, version: int}>, repositories: array<string, array{repository_id: int, fingerprint: string}>} Resolved identities without plaintext secret values.
     *
     * @throws AuthorizationException If the member cannot manage the target workspace.
     * @throws ValidationException If a binding is malformed, unavailable or incompatible.
     */
    public function resolve(Project $project, User $user, array $document, array $bindings): array
    {
        if ((int) $user->current_organization_id !== (int) $project->organization_id
            || ! $project->organization->permits($user, 'manage')) {
            throw new AuthorizationException;
        }
        if (array_diff(array_keys($bindings), ['placements', 'secrets', 'repositories']) !== []) {
            $this->invalid();
        }
        foreach ($bindings as $entries) {
            if (! is_array($entries) || count($entries) > 2000) {
                $this->invalid();
            }
            foreach ($entries as $name => $id) {
                if (! is_string($name) || strlen($name) > 100 || ! preg_match('/\A[a-z][a-z0-9_-]*\z/', $name)
                    || ! is_int($id) || $id < 1) {
                    $this->invalid();
                }
            }
        }
        $resolved = ['placements' => [], 'secrets' => [], 'repositories' => []];
        foreach ($document['environments'] as $environment) {
            $name = $environment['placement'];
            $id = $bindings['placements'][$name] ?? null;
            if (! is_int($id) || $id < 1) {
                $this->invalid();
            }
            $website = Website::query()->where('organization_id', $project->organization_id)
                ->whereHas('server', fn ($query) => $query->where('organization_id', $project->organization_id))
                ->find($id);
            if (! $website) {
                $this->invalid();
            }
            if (PreviewDeployment::query()->where('website_id', $website->id)
                ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))->exists()) {
                throw ValidationException::withMessages(['bindings' => 'Close the active preview through its lifecycle before managing its target with configuration.']);
            }
            $resolved['placements'][$name] ??= ['website_id' => $website->id, 'server_id' => $website->server_id];
            if (collect($environment['resources'] ?? [])->contains(fn ($resource) => $resource['managed'] && in_array($resource['type'], ['mysql', 'postgresql'], true))) {
                $resolved['placements'][$name]['resource_fingerprint'] = hash_hmac('sha256', json_encode([
                    $website->getRawOriginal('database_password'), $website->databaseIdentifier(), $website->server->public_ip,
                ], JSON_THROW_ON_ERROR), (string) config('app.key'));
            }
            if (isset($environment['deploy'])) {
                $reference = $environment['deploy']['repository'];
                $id = $bindings['repositories'][$reference] ?? null;
                if (! is_int($id) || $id < 1) {
                    $this->invalid();
                }
                $repository = Repository::query()->where('organization_id', $project->organization_id)
                    ->where('website_id', $website->id)->find($id);
                if (! $repository || ! $repository->isDeploymentReady()
                    || (int) $repository->provider?->organization_id !== (int) $project->organization_id) {
                    $this->invalid();
                }
                $resolved['repositories'][$reference] = ['repository_id' => $repository->id,
                    'fingerprint' => ApplicationConfigurationRepositoryIdentity::fingerprint($repository)];
            }

            $variables = array_values($environment['variables'] ?? []);
            foreach ($environment['resources'] ?? [] as $resource) {
                foreach ($resource['variable_refs'] ?? [] as $reference) {
                    $variables[] = ['secret_ref' => $reference, 'scope' => 'runtime'];
                }
            }
            foreach ($variables as $variable) {
                $reference = $variable['secret_ref'];
                $id = $bindings['secrets'][$reference] ?? null;
                if (! is_int($id) || $id < 1) {
                    $this->invalid();
                }
                $secret = EnvironmentVariable::query()->where('is_secret', true)
                    ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $project->organization_id))
                    ->find($id, ['id', 'environment_id', 'current_version', 'scope']);
                if (! $secret || ($secret->scope !== 'all' && $secret->scope !== $variable['scope'])) {
                    $this->invalid();
                }
                $resolved['secrets'][$reference] = ['variable_id' => $secret->id, 'version' => $secret->current_version];
            }
        }

        return $resolved;
    }

    /**
     * Reject a binding without disclosing cross-workspace existence or secret metadata.
     *
     * @return never This method always throws.
     *
     * @throws ValidationException With the generic bindings error.
     */
    private function invalid(): never
    {
        throw ValidationException::withMessages(['bindings' => 'A required binding is unavailable or incompatible in this workspace.']);
    }
}
