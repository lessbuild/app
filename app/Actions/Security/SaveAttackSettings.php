<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Exceptions\AccountRuleViolation;
use App\Models\Project;
use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveAttackSettings
{
    /**
     * Save whether a project blocks attackers automatically, for how many hours, and the addresses it never blocks.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  bool  $autoblock
     * @param  int  $hours  1 to 720
     * @param  list<string>  $allowlist  addresses or networks (CIDR)
     * @return SecuritySetting
     */
    public function handle(User $actor, Project $project, bool $autoblock, int $hours, array $allowlist): SecuritySetting
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        foreach ($allowlist as $entry) {
            [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) === false || ($bits !== null && (! ctype_digit($bits) || (int) $bits > 128))) {
                throw new AccountRuleViolation('allowlist', __(':entry isn’t an address or network.', ['entry' => $entry]));
            }
        }
        $settings = SecuritySetting::forProject($project->id);
        $settings->forceFill(['autoblock' => $autoblock, 'block_hours' => max(1, min(720, $hours)), 'allowlist' => array_values(array_unique($allowlist))])->save();

        return $settings;
    }
}
