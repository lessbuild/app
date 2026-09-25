<?php

namespace App\Modules\Monitor\Services;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;

/** Authorizes retained summaries against every original source, without reconstructing historic counts. */
final readonly class IssueDigestSourceAccess
{
    public function __construct(private ProductAuthentication $authentication) {}

    /** @param array<int, mixed> $scopes
     * @return array<int, bool>
     */
    public function allowedScopes(Workspace $workspace, User $recipient, array $scopes): array
    {
        $decisions = array_fill_keys(array_keys($scopes), false);
        if ($workspace->roleFor($recipient) === null) {
            return $decisions;
        }
        if (! $this->authentication->usesCoreAuthority('monitor')) {
            return array_fill_keys(array_keys($scopes), true);
        }

        $valid = [];
        foreach ($scopes as $key => $scope) {
            if ($this->validScope($scope)) {
                $valid[$key] = $scope['sources'];
            }
        }
        $sources = collect($valid)->flatten(1);
        if ($sources->isEmpty()) {
            return $decisions;
        }
        $applications = Application::withTrashed()->where('workspace_id', $workspace->id)
            ->whereKey($sources->pluck('application_id')->unique()->all())
            ->visibleTo($recipient, $workspace)->pluck('id')->flip();
        $environments = Environment::withTrashed()->whereKey($sources->pluck('environment_id')->filter()->unique()->all())
            ->visibleTo($recipient, $workspace)->pluck('application_id', 'id');
        foreach ($valid as $key => $scopeSources) {
            $decisions[$key] = collect($scopeSources)->every(fn (array $source): bool => $applications->has($source['application_id'])
                && ($source['environment_id'] === null || (int) $environments->get($source['environment_id'], 0) === $source['application_id']));
        }

        return $decisions;
    }

    private function validScope(mixed $scope): bool
    {
        if (! is_array($scope) || ($scope['version'] ?? null) !== 1 || ! is_array($scope['sources'] ?? null)
            || ! array_is_list($scope['sources']) || $scope['sources'] === []) {
            return false;
        }
        foreach ($scope['sources'] as $source) {
            if (! is_array($source) || ! is_int($source['application_id'] ?? null) || $source['application_id'] < 1
                || ! array_key_exists('environment_id', $source)
                || ($source['environment_id'] !== null && (! is_int($source['environment_id']) || $source['environment_id'] < 1))) {
                return false;
            }
        }

        return true;
    }
}
