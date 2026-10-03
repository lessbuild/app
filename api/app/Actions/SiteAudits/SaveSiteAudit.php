<?php

declare(strict_types=1);

namespace App\Actions\SiteAudits;

use App\Enums\SiteAuditGoal;
use App\Enums\SiteAuditSchedule;
use App\Exceptions\AccountRuleViolation;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditCompetitor;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveSiteAudit
{
    /**
     * Create a new SaveSiteAudit instance.
     *
     * @param  Entitlements  $entitlements  The plan's limits on audits, competitors and schedules.
     * @param  NormalizeSiteUrl  $urls  Checks and tidies the addresses.
     */
    public function __construct(private readonly Entitlements $entitlements, private readonly NormalizeSiteUrl $urls) {}

    /**
     * Create an audit (no `$audit`) or change one: the site, the journeys, the competitors and the schedule. Competitors
     * are replaced by the list given; ones already saved keep their history.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  SiteAudit|null  $audit
     * @param  string  $name
     * @param  string  $url
     * @param  list<string>  $goals  SiteAuditGoal values, without `custom`
     * @param  string|null  $customGoal  the person's own task, in their words
     * @param  list<array{url: string, name?: string|null, source?: string|null, reason?: string|null}>  $competitors
     * @param  SiteAuditSchedule  $schedule
     * @return SiteAudit
     *
     * @throws AccountRuleViolation
     */
    public function handle(User $actor, Project $project, ?SiteAudit $audit, string $name, string $url, array $goals, ?string $customGoal, array $competitors, SiteAuditSchedule $schedule): SiteAudit
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'audit']);
        $plan = $this->entitlements->for($project->account);
        if ($audit === null) {
            $count = SiteAudit::query()->whereIn('project_id', Project::query()->where('account_id', $project->account_id)->select('id'))->count();
            $decision = $plan->allows('audit.audits.max', $count + 1);
            if (! $decision->allowed) {
                throw new AccountRuleViolation('name', (string) $decision->reason);
            }
        }
        if ($schedule->flag() !== null && ! $plan->has($schedule->flag())) {
            throw new AccountRuleViolation('schedule', __('Your Audit plan doesn’t include this schedule. Upgrade on the billing page to run audits automatically.'));
        }

        $url = $this->urls->handle($url);
        $journeys = [];
        foreach (array_unique($goals) as $value) {
            $goal = SiteAuditGoal::tryFrom($value);
            if ($goal !== null && $goal !== SiteAuditGoal::Custom) {
                $journeys[] = ['key' => $goal->value, 'goal' => $goal->instruction()];
            }
        }
        $customGoal = trim((string) $customGoal);
        if ($customGoal !== '') {
            $journeys[] = ['key' => SiteAuditGoal::Custom->value, 'goal' => mb_substr($customGoal, 0, 300)];
        }
        if ($journeys === [] || count($journeys) > 5) {
            throw new AccountRuleViolation('goals', __('Choose between one and five tasks for the visitor to try.'));
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $wanted = [];
        foreach ($competitors as $index => $competitor) {
            $competitorUrl = $this->urls->handle((string) $competitor['url'], "competitors.{$index}.url");
            $competitorHost = (string) parse_url($competitorUrl, PHP_URL_HOST);
            if ($competitorHost === $host || isset($wanted[$competitorHost])) {
                continue;
            }
            $wanted[$competitorHost] = [
                'url' => $competitorUrl,
                'name' => mb_substr(trim((string) ($competitor['name'] ?? '')) ?: $competitorHost, 0, 120),
                'source' => ($competitor['source'] ?? null) === 'suggested' ? 'suggested' : 'customer',
                'reason' => is_string($competitor['reason'] ?? null) ? mb_substr($competitor['reason'], 0, 500) : null,
            ];
        }
        $limit = $plan->limit('audit.competitors');
        if ($limit !== null && count($wanted) > $limit) {
            throw new AccountRuleViolation('competitors', trans_choice('Your plan compares with up to :count competitor. Upgrade on the billing page to add more.|Your plan compares with up to :count competitors. Upgrade on the billing page to add more.', $limit, ['count' => $limit]));
        }

        return DB::transaction(function () use ($actor, $project, $audit, $name, $url, $journeys, $wanted, $schedule): SiteAudit {
            $audit ??= new SiteAudit;
            $scheduleChanged = ! $audit->exists || $audit->schedule !== $schedule;
            $audit->forceFill([
                'project_id' => $project->id, 'name' => mb_substr(trim($name) ?: (string) parse_url($url, PHP_URL_HOST), 0, 120), 'url' => $url,
                'journeys' => $journeys, 'schedule' => $schedule,
                'next_run_at' => $scheduleChanged ? $schedule->nextRunAfter(now()) : $audit->next_run_at,
            ]);
            if (! $audit->exists) {
                $audit->forceFill(['created_by' => $actor->id]);
            }
            $audit->save();

            $existing = $audit->competitors()->get()->keyBy(fn (SiteAuditCompetitor $competitor): string => (string) parse_url($competitor->url, PHP_URL_HOST));
            foreach ($existing as $competitorHost => $competitor) {
                if (! isset($wanted[$competitorHost])) {
                    $competitor->delete();
                }
            }
            foreach ($wanted as $competitorHost => $values) {
                $competitor = $existing->get($competitorHost) ?? new SiteAuditCompetitor;
                $competitor->forceFill(['site_audit_id' => $audit->id, 'status' => 'confirmed'] + $values)->save();
            }

            return $audit->refresh();
        });
    }
}
