<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Exceptions\AccountRuleViolation;
use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;

final class SetSecurityGate
{
    /**
     * Create a new SetSecurityGate instance.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes the deploy gate.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Choose the lowest severity of known vulnerability that stops an environment's deploys (critical, or high and
     * above), or turn the gate off. Deploys queued from now on use it.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  string|null  $level  critical, high or null
     * @return void
     */
    public function handle(User $actor, Environment $environment, ?string $level): void
    {
        Gate::forUser($actor)->authorize('manageService', [$environment->project, 'security']);
        if ($level !== null && ! $this->entitlements->for($environment->project->account)->has('security.deploy_gate')) {
            throw new AccountRuleViolation('security_gate', __('The deploy gate comes with the Pro Security plan and above.'));
        }
        $environment->forceFill(['security_gate' => in_array($level, ['critical', 'high'], true) ? $level : null])->save();
    }
}
