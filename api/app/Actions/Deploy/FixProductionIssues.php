<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use App\Services\Deploy\ProductionCheck;
use Illuminate\Support\Facades\Gate;

final class FixProductionIssues
{
    /**
     * Create a new FixProductionIssues instance.
     *
     * Applies the production check's one-click fixes.
     *
     * @param  ProductionCheck  $check  Finds the issues, so only current ones are fixed.
     * @param  SaveEnvironmentVariable  $variables  Sets variables the usual way: versioned, and through approval when the environment needs it.
     * @param  SaveEnvironmentProcess  $processes  Adds workers within the plan.
     */
    public function __construct(private readonly ProductionCheck $check, private readonly SaveEnvironmentVariable $variables, private readonly SaveEnvironmentProcess $processes) {}

    /**
     * Fix the given issues (all fixable ones when none are given), checking again first so a stale page can't undo a
     * later change. Returns the keys it fixed.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function handle(User $actor, Environment $environment, array $keys = []): array
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $fixed = [];
        foreach ($this->check->issues($environment) as $issue) {
            if (! $issue->fixable() || ($keys !== [] && ! in_array($issue->key, $keys, true))) {
                continue;
            }
            foreach ($issue->variables as $key => $value) {
                $existing = $environment->variables->firstWhere('key', $key);
                $this->variables->handle($actor, $environment, ['key' => $key, 'value' => $value, 'is_secret' => (bool) $existing?->is_secret, 'scope' => $existing->scope ?? 'runtime']);
            }
            if ($issue->process !== null) {
                $this->processes->handle($actor, $environment, [...$issue->process, 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true]);
            }
            $fixed[] = $issue->key;
        }

        return $fixed;
    }
}
