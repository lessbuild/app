<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceCredentialMutations;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class WorkspaceCredentialMutationController
{
    public function create(Request $request, Workspace $workspace, ResolvePlatformUser $platformUsers, WorkspaceCredentialMutations $mutations): Response
    {
        $actor = $this->actor($request, $platformUsers);
        $options = $mutations->options($actor, $workspace);

        return response()->view('core::workspaces.credentials-create', [
            'user' => $actor,
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($actor),
            'contextProjects' => collect(),
            'options' => $options->options,
            'sourceUnavailable' => ! $options->available,
            'idempotencyKey' => (string) Str::uuid(),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(Request $request, Workspace $workspace, ResolvePlatformUser $platformUsers, WorkspaceCredentialMutations $mutations): Response
    {
        $actor = $this->actor($request, $platformUsers);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'product' => ['required', Rule::in(['deployer', 'monitor'])],
            'credential_type' => ['required', 'string', 'max:80'],
            'target_key' => ['required', 'string', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
            'permissions' => ['sometimes', 'array', 'max:3'],
            'permissions.*' => [Rule::in(['read', 'deploy', 'manage'])],
            'canonical_project_ids' => ['sometimes', 'array', 'max:100'],
            'canonical_project_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $outcome = $mutations->create(
            $actor, $workspace, $data['product'], $data['credential_type'], $data['target_key'],
            $data['name'] ?? null, isset($data['expires_in_days']) ? (int) $data['expires_in_days'] : null,
            $data['permissions'] ?? [], $data['canonical_project_ids'] ?? [], $data['idempotency_key'],
        );

        return $this->result($request, $workspace, $outcome);
    }

    public function rotate(Request $request, Workspace $workspace, string $credential, ResolvePlatformUser $platformUsers, WorkspaceCredentialMutations $mutations): Response
    {
        $actor = $this->actor($request, $platformUsers);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $outcome = $mutations->rotate($actor, $workspace, $credential, $data['idempotency_key']);

        return $this->result($request, $workspace, $outcome);
    }

    public function revoke(Request $request, Workspace $workspace, string $credential, ResolvePlatformUser $platformUsers, WorkspaceCredentialMutations $mutations): Response
    {
        $actor = $this->actor($request, $platformUsers);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $outcome = $mutations->revoke($actor, $workspace, $credential, $data['idempotency_key']);

        return $this->result($request, $workspace, $outcome);
    }

    private function actor(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform');
        abort_unless($principal !== null, 401);
        $actor = $platformUsers->resolve($principal, 'deployer');
        abort_if(! $actor instanceof PlatformUser, 403);

        return $actor;
    }

    private function result(Request $request, Workspace $workspace, CredentialMutationOutcome $outcome): Response
    {
        $secret = $outcome->secretForImmediateResponse();

        return response()->view('core::workspaces.credential-mutation-result', [
            'workspace' => $workspace,
            'outcome' => $outcome,
            'secret' => $secret,
            'user' => $request->user('platform'),
            'workspaces' => $this->workspaces($request->user('platform')),
            'contextProjects' => collect(),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function workspaces(PlatformUser $actor): Collection
    {
        return Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $actor->getKey()))
            ->orderBy('name')->get();
    }
}
