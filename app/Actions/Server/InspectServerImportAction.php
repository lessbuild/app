<?php

namespace App\Actions\Server;

use App\Data\ServerImportAssessmentResult;
use App\Models\ServerImportAssessment;
use App\Models\User;
use App\Services\PlanLimits;
use App\Services\ServerDiscovery;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectServerImportAction
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly ServerDiscovery $discovery,
    ) {}

    /**
     * Inspect validated SSH details and persist a session-bound import assessment.
     *
     * @param  User  $user  Actor whose current workspace owns the assessment.
     * @param  array{name: string, type: string, public_ip: string, ssh_port: int|string, ssh_private_key: string}  $attributes  Validated import details.
     * @return ServerImportAssessmentResult The assessment and its one-time session token.
     *
     * @throws ValidationException If the workspace limit or remote inspection rejects the import.
     */
    public function handle(User $user, array $attributes): ServerImportAssessmentResult
    {
        $this->limits->usage($user, 'servers')['allowed'] || throw ValidationException::withMessages(['plan' => __('Your plan’s server limit has been reached.')]);
        $configuration = [
            'name' => $attributes['name'], 'type' => $attributes['type'],
            'public_ip' => $attributes['public_ip'], 'ssh_port' => (int) $attributes['ssh_port'],
            'ssh_private_key' => trim($attributes['ssh_private_key']),
        ];

        try {
            $report = $this->discovery->inspect($configuration);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['connection' => str($exception->getMessage())->limit(1000)->toString()]);
        }

        $token = Str::random(64);
        $assessment = ServerImportAssessment::query()->create([
            'organization_id' => $user->current_organization_id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'configuration' => $configuration,
            'report' => $report,
            'expires_at' => now()->addMinutes(30),
        ]);

        return new ServerImportAssessmentResult($assessment, $token);
    }
}
