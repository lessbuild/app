<?php

namespace App\Core\Services;

use App\Core\Data\Credentials\CredentialCreateOptions;
use App\Core\Data\Credentials\CredentialMutationCommand;
use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkspaceCredentialMutations
{
    public function __construct(
        private readonly WorkspaceCredentialMutationProviderRegistry $providers,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    public function options(PlatformUser $actor, Workspace $workspace): CredentialCreateOptions
    {
        $membership = $this->access->activeMembership($actor, $workspace);
        abort_if($membership === null, 404);
        $projects = $this->accessibleProjects($actor, $workspace);
        $options = collect();
        $available = true;

        foreach ($membership->productAccess as $grant) {
            if (! config('platform.products.'.$grant->product.'.enabled', false)
                || ! $this->access->hasProductAccess($membership, $grant->product)) {
                continue;
            }
            $provider = $this->providers->get($grant->product);
            if ($provider === null) {
                continue;
            }
            try {
                $snapshot = $provider->createOptions($actor, $workspace, $projects);
            } catch (LostConnectionException|QueryException|SQLiteDatabaseDoesNotExistException) {
                $available = false;

                continue;
            }
            $available = $available && $snapshot->available;
            $options = $options->concat($snapshot->options);
        }

        return new CredentialCreateOptions($options->unique(fn ($option): string => $option->product.'|'.$option->type.'|'.$option->targetKey)->values(), $available);
    }

    /** @param list<string> $permissions @param list<string> $canonicalProjectIds */
    public function create(
        PlatformUser $actor,
        Workspace $workspace,
        string $product,
        string $type,
        string $targetKey,
        ?string $name,
        ?int $expiresInDays,
        array $permissions,
        array $canonicalProjectIds,
        string $idempotencyKey,
    ): CredentialMutationOutcome {
        $command = new CredentialMutationCommand(
            'create', $product, $type, null, $targetKey, $name, $expiresInDays,
            array_values(array_unique($permissions)), array_values(array_unique(array_map('strval', $canonicalProjectIds))),
            '', '',
        );

        return $this->execute($actor, $workspace, $command, $idempotencyKey);
    }

    public function rotate(PlatformUser $actor, Workspace $workspace, string $credentialKey, string $idempotencyKey): CredentialMutationOutcome
    {
        return $this->execute($actor, $workspace, $this->referenceCommand('rotate', $credentialKey), $idempotencyKey);
    }

    public function revoke(PlatformUser $actor, Workspace $workspace, string $credentialKey, string $idempotencyKey): CredentialMutationOutcome
    {
        return $this->execute($actor, $workspace, $this->referenceCommand('revoke', $credentialKey), $idempotencyKey);
    }

    private function execute(PlatformUser $actor, Workspace $workspace, CredentialMutationCommand $intent, string $idempotencyKey): CredentialMutationOutcome
    {
        abort_unless(Str::isUuid($idempotencyKey), 422, 'A valid idempotency key is required.');
        $this->assertCoreAuthority($actor, $workspace, $intent->product);
        $provider = $this->providers->get($intent->product);
        abort_if($provider === null, 404, 'Credential source is unavailable.');

        $inputHash = hash('sha256', json_encode([
            'action' => $intent->action,
            'product' => $intent->product,
            'type' => $intent->credentialType,
            'credential_key' => $intent->credentialKey,
            'target_key' => $intent->targetKey,
            'name' => $intent->name,
            'expires_in_days' => $intent->expiresInDays,
            'permissions' => $this->sorted($intent->permissions),
            'canonical_project_ids' => $this->sorted($intent->canonicalProjectIds),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $id = $this->reserveIdempotency($actor, $workspace, $intent, $idempotencyHash, $inputHash);
        $receipt = DB::connection('core')->table('workspace_credential_mutations')->where('id', $id)->first();
        $command = new CredentialMutationCommand(
            $intent->action, $intent->product, $intent->credentialType, $intent->credentialKey, $intent->targetKey,
            $intent->name, $intent->expiresInDays, $intent->permissions, $intent->canonicalProjectIds, $id, $inputHash,
        );
        if (in_array($receipt->status, ['issued', 'secret_unavailable', 'revoked'], true)) {
            $this->assertCoreAuthority($actor, $workspace, $intent->product);
            $nativeOutcome = $provider->mutate($actor, $workspace, new CredentialMutationCommand(
                $command->action, $command->product, $command->credentialType, $command->credentialKey, $command->targetKey,
                $command->name, $command->expiresInDays, $command->permissions, $command->canonicalProjectIds,
                $command->operationId, $command->inputHash, true,
            ));
            abort_unless($nativeOutcome->secretForImmediateResponse() === null
                && hash_equals((string) $receipt->credential_key, $nativeOutcome->credentialKey), 409, 'The credential operation receipt no longer matches its native product receipt.');
            $this->assertCoreAuthority($actor, $workspace, $intent->product);

            return new CredentialMutationOutcome(
                $receipt->status === 'revoked' ? 'revoked' : 'secret_unavailable', (string) $receipt->credential_key,
                (string) $receipt->credential_type, (string) $receipt->credential_name,
            );
        }

        $outcome = $provider->mutate($actor, $workspace, $command);

        $status = match ($intent->action) {
            'revoke' => 'revoked',
            default => $outcome->secretForImmediateResponse() === null ? 'secret_unavailable' : 'issued',
        };
        DB::connection('core')->table('workspace_credential_mutations')->where('id', $id)->where('status', 'pending')->update([
            'status' => $status,
            'credential_key' => $outcome->credentialKey,
            'credential_type' => $outcome->credentialType,
            'credential_name' => $outcome->credentialName,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCoreAuthority($actor, $workspace, $intent->product);

        return $outcome;
    }

    private function reserveIdempotency(
        PlatformUser $actor,
        Workspace $workspace,
        CredentialMutationCommand $command,
        string $idempotencyHash,
        string $inputHash,
    ): string {
        $query = DB::connection('core')->table('workspace_credential_mutations');
        $existing = (clone $query)->where('actor_id', $actor->getKey())->where('workspace_id', $workspace->getKey())
            ->where('product', $command->product)->where('action', $command->action)
            ->where('idempotency_key_hash', $idempotencyHash)->first();
        if ($existing !== null) {
            abort_unless(hash_equals((string) $existing->input_hash, $inputHash), 409, 'This idempotency key was already used for different credential input.');

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        try {
            $query->insert([
                'id' => $id, 'actor_id' => $actor->getKey(), 'workspace_id' => $workspace->getKey(),
                'product' => $command->product, 'action' => $command->action,
                'idempotency_key_hash' => $idempotencyHash, 'input_hash' => $inputHash,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $existing = $query->where('actor_id', $actor->getKey())->where('workspace_id', $workspace->getKey())
                ->where('product', $command->product)->where('action', $command->action)
                ->where('idempotency_key_hash', $idempotencyHash)->first();
            abort_if($existing === null, 409, 'Credential operation could not be reserved. Retry with a new form.');
            abort_unless(hash_equals((string) $existing->input_hash, $inputHash), 409, 'This idempotency key was already used for different credential input.');

            return (string) $existing->id;
        }

        return $id;
    }

    private function referenceCommand(string $action, string $credentialKey): CredentialMutationCommand
    {
        $parts = explode(':', $credentialKey);
        abort_unless(count($parts) >= 3 && in_array($parts[0], ['deployer', 'monitor'], true), 404);

        return new CredentialMutationCommand($action, $parts[0], implode(':', array_slice($parts, 1, -1)), $credentialKey, null, null, null, [], [], '', '');
    }

    private function assertCoreAuthority(PlatformUser $actor, Workspace $workspace, string $product): void
    {
        $membership = $this->access->activeMembership($actor, $workspace);
        abort_if(! config('platform.products.'.$product.'.enabled', false)
            || $membership === null || ! $this->access->hasProductAccess($membership, $product), 404);
    }

    /** @return Collection<int, Project> */
    private function accessibleProjects(PlatformUser $actor, Workspace $workspace): Collection
    {
        return Project::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('revoked_at'))
            ->whereHas('products', fn ($query) => $query->whereIn('product', ['deployer', 'monitor'])->where('status', 'active'))
            ->with('products')->get();
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return $values;
    }
}
