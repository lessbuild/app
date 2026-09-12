<?php

namespace App\Actions\Server;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\PlanLimits;
use App\Services\ServerProviderResolver;
use App\Services\SshKeyPair;
use Throwable;

class CreateServerAction
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly ServerProviderResolver $providers,
        private readonly SshKeyPair $keypair,
        private readonly CreateCloudServerAction $createCloudServer,
    ) {}

    /**
     * Create a server within workspace limits, provision it through the selected cloud provider, and queue initialization.
     *
     * @param  User  $user  User whose current organization owns the server.
     * @param  Provider  $provider  Validated cloud-provider connection in the user's workspace.
     * @param  ServerTypeEnum  $type  Validated server role.
     * @param  string  $name  Validated display name used for provider and server naming.
     * @param  array<string, mixed>  $cloudParameters  Validated provider region, size and image selections.
     * @param  list<int|string>  $recipeIds  Validated workspace recipe IDs in requested order.
     * @return Server The queued or failed server record, including its provisioning outcome.
     */
    public function handle(
        User $user,
        Provider $provider,
        ServerTypeEnum $type,
        string $name,
        array $cloudParameters,
        array $recipeIds = [],
    ): Server {
        $cloudProvider = $this->providers->resolve($provider);

        $server = $this->limits->withinLimit($user, 'servers', function ($organization) use ($user, $provider, $type, $name, $recipeIds): Server {
            $server = $organization->servers()->create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'type' => $type,
                'name' => str($name)->slug()->limit(31, ''),
                'provisioning_status' => Server::STATUS_QUEUED,
                'ssh_public_key' => $this->keypair->publicKey(),
                'ssh_private_key' => $this->keypair->privateKey(),
            ]);

            $recipeAssignments = collect($recipeIds)
                ->values()
                ->mapWithKeys(fn ($recipeId, $position): array => [
                    (int) $recipeId => ['position' => $position],
                ]);
            $server->recipes()->sync($recipeAssignments);
            $server->captureProvisioningRecipes();

            return $server;
        });

        /** @var CloudServerData|null $cloudServer */
        $cloudServer = null;

        try {
            $sshKey = $cloudProvider->createSshKey($name, $server->ssh_public_key);

            // Persist this immediately so a failed cleanup can be retried when the
            // failed server record is deleted.
            $server->update([
                'ssh_fingerprint' => $sshKey->fingerprint,
                'ssh_key_owned' => $sshKey->created,
            ]);

            $cloudServer = $this->createCloudServer->handle($server, $cloudProvider, [
                ...$cloudParameters,
                'name' => str()->slug($name),
                'ssh_keys' => [$sshKey->fingerprint],
            ]);

            $server->update([
                'identifier' => $cloudServer->identifier,
                'name' => $cloudServer->name,
                'region' => $cloudServer->region,
                'size' => $cloudServer->size,
                'image' => $cloudServer->image,
            ]);

            InitialiseServerJob::dispatch($server);
        } catch (Throwable $exception) {
            report($exception);

            if ($cloudServer !== null) {
                try {
                    $cloudProvider->deleteServer($cloudServer->identifier);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            $this->cleanUpSshKey($server, $cloudProvider);

            $server->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
                'provisioning_failure_phase' => Server::FAILURE_CREATION,
            ]);
        }

        return $server;
    }

    /**
     * Attempt deletion of an application-owned provider SSH key and clear its fingerprint only on success.
     */
    private function cleanUpSshKey(Server $server, ServerProvider $provider): void
    {
        if (! $server->ssh_fingerprint || ! $server->ssh_key_owned) {
            return;
        }

        try {
            if ($provider->deleteSshKey($server->ssh_fingerprint)) {
                $server->update(['ssh_fingerprint' => null]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
