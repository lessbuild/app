<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\ApplyZoneSecurity;
use App\Models\Project;
use App\Models\SecurityZone;
use App\Models\User;
use App\Queries\Security\ProjectZonesQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;

final class SetZoneSecurity
{
    /**
     * Create a new SetZoneSecurity instance.
     *
     * @param  ProjectZonesQuery  $zones  Checks the zone belongs to the project.
     * @param  Entitlements  $entitlements  Checks the plan includes firewall controls.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly ProjectZonesQuery $zones, private readonly Entitlements $entitlements, private readonly RecordAuditEntry $audit) {}

    /**
     * Save a zone's security level, bot fight mode and "under attack" mode, and apply them at Cloudflare.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $zoneId
     * @param  string  $level  one of SecurityZone::LEVELS' keys
     * @param  bool  $botFight
     * @param  bool  $underAttack
     * @return SecurityZone
     */
    public function handle(User $actor, Project $project, string $zoneId, string $level, bool $botFight, bool $underAttack): SecurityZone
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        if (! $this->entitlements->for($project->account)->has('security.waf')) {
            throw new AccountRuleViolation('security_level', __('Firewall and bot controls come with the Team Security plan.'));
        }
        $entry = $this->zones->handle($project)->first(fn (array $row): bool => $row['zone']->zone_id === $zoneId)
            ?? throw new AccountRuleViolation('security_level', __('That zone isn’t behind any of this project’s domains.'));
        $zone = $entry['zone'];
        $zone->forceFill(['security_level' => array_key_exists($level, SecurityZone::LEVELS) ? $level : 'medium', 'bot_fight_mode' => $botFight, 'under_attack' => $underAttack, 'last_error' => null])->save();
        $this->audit->handle(AuditAction::SecurityZoneChanged, $actor, $project->account_id, ['zone' => $zone->zone_name ?? $entry['domains'][0], 'under_attack' => $underAttack], $project->id);
        ApplyZoneSecurity::dispatch($zone->id)->afterCommit();

        return $zone;
    }
}
