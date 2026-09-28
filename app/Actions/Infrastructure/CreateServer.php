<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Contracts\Infrastructure\ServerProvider;
use App\Enums\AuditAction;
use App\Enums\ServerType;
use App\Jobs\Infrastructure\InitialiseServer;
use App\Models\Account;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Server;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ProvisioningScriptRenderer;
use App\Services\Infrastructure\Scripts\Database\InstallMysqlScript;
use App\Services\Infrastructure\ServerProviderResolver;
use App\Services\Infrastructure\ServerProvisioningPlan;
use App\Services\Infrastructure\SshKeyPair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class CreateServer
{
    /**
     * Create a new CreateServer instance.
     *
     * Creates a server at a cloud provider and starts provisioning it.
     *
     * @param  Entitlements  $entitlements  Checks the plan's server limit.
     * @param  ServerProviderResolver  $providers  Talks to the chosen provider.
     * @param  ServerProvisioningPlan  $plan  The provisioning steps and scripts for the server's type and recipes.
     * @param  ProvisioningScriptRenderer  $renderer  Renders the first-boot script.
     * @param  RecordAuditEntry  $audit  Records the new server.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ServerProviderResolver $providers,
        private readonly ServerProvisioningPlan $plan,
        private readonly ProvisioningScriptRenderer $renderer,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Create a cloud server that provisions itself: a new SSH key is registered with the provider, and the provisioning script
     * goes in as user data, ending with the chosen recipes (snapshotted in order). If the provider refuses, whatever was created is removed and the server is marked failed.
     * The one-time root password is on the returned model (`provisioningRootPassword()`).
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  array{provider_id: int|string, type: string, name: string, region: string, size: string, image: string, recipe_ids?: list<int>}  $data
     * @return Server
     */
    public function handle(Account $account, User $actor, array $data): Server
    {
        $server = DB::transaction(function () use ($account, $actor, $data): Server {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('create', [Server::class, $account]);
            $decision = $this->entitlements->for($account)->allows('infrastructure.servers.max', Server::query()->where('account_id', $account->id)->count() + 1);
            if (! $decision->allowed) {
                throw ValidationException::withMessages(['plan' => $decision->reason]);
            }
            $provider = Provider::query()->where('account_id', $account->id)->findOrFail((int) $data['provider_id']);
            if (! $provider->type->hostsServers()) {
                throw ValidationException::withMessages(['provider_id' => __('Choose a provider that hosts servers.')]);
            }
            // A snapshot in the chosen order, so editing a recipe later doesn't change what this server ran.
            $recipes = Recipe::query()->where('account_id', $account->id)->whereKey($data['recipe_ids'] ?? [])->get()->keyBy('id');
            $snapshot = [];
            foreach ($data['recipe_ids'] ?? [] as $id) {
                $recipe = $recipes->get($id) ?? throw ValidationException::withMessages(['recipe_ids' => __('Choose recipes from this account.')]);
                $snapshot[] = ['name' => $recipe->name, 'description' => $recipe->description, 'script' => $recipe->script];
            }
            $keys = app(SshKeyPair::class);
            $type = ServerType::from($data['type']);
            $server = new Server;
            $server->forceFill([
                'account_id' => $account->id,
                'created_by' => $actor->id,
                'provider_id' => $provider->id,
                'type' => $type,
                'name' => Str::limit(Str::slug($data['name']), 31, '') ?: 'server',
                'provisioning_status' => Server::STATUS_QUEUED,
                'ssh_public_key' => $keys->publicKey(),
                'ssh_private_key' => $keys->privateKey(),
                'mysql_root_password' => in_array(InstallMysqlScript::class, $this->plan->steps($type), true) ? Str::random(40) : null,
                'recipe_snapshot' => $snapshot,
            ])->save();
            $this->audit->handle(AuditAction::ServerCreated, $actor, $account->id, ['server' => $server->name, 'provider' => $provider->name, 'type' => $type->value]);

            return $server;
        }, attempts: 3);

        $server->setProvisioningRootPassword(Str::random(40));
        $client = null;
        $created = null;
        try {
            $client = $this->providers->resolve($server->provider ?? throw new LogicException('The provider was just checked.'));
            $key = $client->createSshKey($server->name, (string) $server->ssh_public_key);
            // Saved at once so deleting the failed server can still remove the key.
            $server->forceFill(['ssh_fingerprint' => $key->fingerprint, 'ssh_key_owned' => $key->created])->save();
            $created = $client->createServer([
                'name' => $server->name, 'region' => $data['region'], 'size' => $data['size'], 'image' => $data['image'],
                'ssh_keys' => [$key->fingerprint], 'user_data' => $this->renderer->server($server, $this->plan->scripts($server)),
            ]);
            $server->forceFill(['identifier' => (string) $created->identifier, 'name' => $created->name, 'region' => $created->region, 'size' => $created->size, 'image' => $created->image])->save();
            InitialiseServer::dispatch($server->id, (string) $server->initialization_token);
        } catch (Throwable $exception) {
            report($exception);
            $this->cleanUp($server, $client, $created?->identifier);
            $server->forceFill([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => Str::limit($exception->getMessage(), 2000),
                'provisioning_failure_phase' => Server::FAILURE_CREATION,
            ])->save();
        }

        return $server;
    }

    /**
     * Delete what was already created at the provider (the server, and an SSH key we added) after creation failed
     * part-way, so nothing is left running and billing. Errors here are reported rather than thrown, so the original
     * failure is what the person sees.
     *
     * @param  Server  $server
     * @param  ServerProvider|null  $client
     * @param  string|int|null  $identifier
     * @return void
     */
    private function cleanUp(Server $server, ?ServerProvider $client, int|string|null $identifier): void
    {
        if ($client === null) {
            return;
        }
        try {
            if ($identifier !== null) {
                $client->deleteServer($identifier);
            }
            if ($server->ssh_fingerprint !== null && $server->ssh_key_owned && $client->deleteSshKey($server->ssh_fingerprint)) {
                $server->forceFill(['ssh_fingerprint' => null])->save();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
