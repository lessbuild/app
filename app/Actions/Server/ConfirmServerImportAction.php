<?php

namespace App\Actions\Server;

use App\Jobs\Server\RetryRemoteServerProvisioningJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\ServerImportAssessment;
use App\Models\ServerLogSnapshot;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\PlanLimits;
use Illuminate\Validation\ValidationException;
use phpseclib4\Crypt\PublicKeyLoader;

class ConfirmServerImportAction
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly PrepareServerProvisioningAction $prepare,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Consume a session-bound import assessment and queue its remote provisioning after the transaction commits.
     *
     * @param  User  $user  User whose current organization owns the imported server.
     * @param  ServerImportAssessment  $assessment  Session-bound assessment being consumed.
     * @param  string  $token  Plaintext assessment token from the user's session.
     * @return Server The queued imported server.
     *
     * @throws ValidationException If the locked assessment is expired, consumed or does not match the session token.
     */
    public function handle(User $user, ServerImportAssessment $assessment, string $token): Server
    {
        /** @var Server $server */
        $server = $this->limits->withinLimit($user, 'servers', function ($organization) use ($user, $assessment, $token): Server {
            $lockedAssessment = ServerImportAssessment::query()->lockForUpdate()->findOrFail($assessment->id);
            if (! $lockedAssessment->isUsableBy($user, $token)) {
                throw ValidationException::withMessages([
                    'confirmation' => __('This import assessment expired or was already used. Run the inspection again.'),
                ]);
            }

            $configuration = $lockedAssessment->configuration;
            $server = $organization->servers()->create([
                'user_id' => $user->id,
                'type' => ServerTypeEnum::from($configuration['type']),
                'name' => str($configuration['name'])->slug()->limit(31, ''),
                'region' => 'External',
                'image' => 'Existing Ubuntu',
                'size' => 'Custom',
                'public_ip' => $configuration['public_ip'],
                'ssh_port' => $configuration['ssh_port'],
                'ssh_private_key' => $configuration['ssh_private_key'],
                'ssh_public_key' => PublicKeyLoader::loadPrivateKey($configuration['ssh_private_key'])->getPublicKey()->toString('OpenSSH'),
                'ssh_host_key' => $lockedAssessment->report['known_host'],
                'ssh_host_fingerprint' => $lockedAssessment->report['fingerprint'],
                'ssh_key_owned' => false,
                'provisioning_status' => Server::STATUS_QUEUED,
            ]);
            $this->prepare->handle($server);
            $server->update(['password' => $server->provisioningRootPassword()]);
            $server->logSnapshots()->create([
                'type' => 'provisioning',
                'status' => ServerLogSnapshot::STATUS_QUEUED,
            ]);
            RetryRemoteServerProvisioningJob::dispatch($server->id, $server->provisioning_token)->afterCommit();
            $lockedAssessment->update(['consumed_at' => now()]);

            return $server;
        });

        $this->activity->record($server, $user->id, 'server', 'Existing server imported and provisioning queued.');

        return $server;
    }
}
