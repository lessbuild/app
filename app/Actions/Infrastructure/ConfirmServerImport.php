<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\ServerType;
use App\Jobs\Infrastructure\RunServerProvisioning;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerImportAssessment;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\Scripts\Database\InstallMysqlScript;
use App\Services\Infrastructure\ServerKeys;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConfirmServerImport
{
    /**
     * Imports a server that has been inspected.
     *
     * @param  Entitlements  $entitlements  Checks the plan's server limit.
     * @param  ServerProvisioningPlan  $plan  The provisioning steps an imported server skips or runs.
     * @param  ServerKeys  $keys  Reads the platform's public key to install.
     * @param  RecordAuditEntry  $audit  Records the import.
     */
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ServerProvisioningPlan $plan,
        private readonly ServerKeys $keys,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Create the server from an unexpired, unused assessment and start provisioning it over SSH. The root password for this
     * run is on the returned model (`provisioningRootPassword()`).
     */
    public function handle(Account $account, User $actor, ServerImportAssessment $assessment, string $token): Server
    {
        Gate::forUser($actor)->authorize('create', [Server::class, $account]);

        return DB::transaction(function () use ($account, $actor, $assessment, $token): Server {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $locked = ServerImportAssessment::query()->lockForUpdate()->findOrFail($assessment->id);
            if (! $locked->isUsableBy($actor, $account->id, $token)) {
                throw ValidationException::withMessages(['confirmation' => __('This inspection expired or was already used. Inspect the server again.')]);
            }
            if (! $this->entitlements->for($account)->allows('infrastructure.servers.max', Server::query()->where('account_id', $account->id)->count() + 1)->allowed) {
                throw ValidationException::withMessages(['plan' => __('Your plan’s server limit has been reached.')]);
            }
            $configuration = $locked->configuration;
            $type = ServerType::from($configuration['type']);
            $password = Str::random(40);
            $server = new Server;
            $server->forceFill([
                'account_id' => $account->id, 'created_by' => $actor->id, 'type' => $type,
                'name' => Str::limit(Str::slug($configuration['name']), 31, '') ?: 'server',
                'region' => 'External', 'image' => 'Existing Ubuntu', 'size' => 'Custom',
                'public_ip' => $configuration['public_ip'], 'ssh_port' => $configuration['ssh_port'],
                'ssh_private_key' => $configuration['ssh_private_key'], 'ssh_public_key' => $this->keys->publicKeyOf($configuration['ssh_private_key']),
                'ssh_host_key' => (string) ($locked->report['known_host'] ?? ''), 'ssh_host_fingerprint' => (string) ($locked->report['fingerprint'] ?? ''),
                'ssh_key_owned' => false, 'provisioning_status' => Server::STATUS_QUEUED, 'password' => $password,
                'mysql_root_password' => in_array(InstallMysqlScript::class, $this->plan->steps($type), true) ? Str::random(40) : null,
            ])->save();
            $server->setProvisioningRootPassword($password);
            $server->logSnapshots()->create(['type' => 'provisioning', 'status' => ServerLogSnapshot::STATUS_QUEUED]);
            $locked->forceFill(['consumed_at' => CarbonImmutable::now('UTC')])->save();
            RunServerProvisioning::dispatch($server->id, (string) $server->provisioning_token)->afterCommit();
            $this->audit->handle(AuditAction::ServerImported, $actor, $account->id, ['server' => $server->name, 'ip' => $server->public_ip]);

            return $server;
        }, attempts: 3);
    }
}
