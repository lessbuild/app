<?php

declare(strict_types=1);

namespace App\Queries\SiteAudits;

use App\Data\SiteAudits\SiteAuditDetail;
use App\Data\SiteAudits\SiteAuditListItem;
use App\Data\SiteAudits\SiteAuditPlan;
use App\Data\SiteAudits\SiteAuditReport;
use App\Data\SiteAudits\SiteAuditRunSummary;
use App\Enums\SiteAuditCategory;
use App\Enums\SiteAuditGoal;
use App\Enums\SiteAuditSchedule;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditCompetitor;
use App\Models\SiteAuditFinding;
use App\Models\SiteAuditJourney;
use App\Models\SiteAuditRun;
use App\Models\SiteAuditStep;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\SiteAudits\SiteAuditUsage;

/** Reads audits, their runs and reports for the Audit pages. */
final class SiteAuditQuery
{
    /**
     * Create a new SiteAuditQuery instance.
     *
     * @param  Entitlements  $entitlements  The account's Audit plan.
     * @param  SiteAuditUsage  $usage  Counts this month's audits.
     */
    public function __construct(private readonly Entitlements $entitlements, private readonly SiteAuditUsage $usage) {}

    /**
     * Get the project's audits with their latest runs, newest first.
     *
     * @param  Project  $project
     * @return list<SiteAuditListItem>
     */
    public function audits(Project $project): array
    {
        return array_values($project->siteAudits()->with('latestRun')->withCount(['competitors' => fn ($query) => $query->where('status', 'confirmed')])->latest('id')->get()
            ->map(fn (SiteAudit $audit): SiteAuditListItem => new SiteAuditListItem(
                $audit->id, $audit->name, $audit->url, $audit->schedule->value, (int) $audit->getAttribute('competitors_count'),
                $audit->latestRun === null ? null : SiteAuditRunSummary::from($audit->latestRun),
            ))->all());
    }

    /**
     * Get what the account's plan allows and what the person may do.
     *
     * @param  User  $user
     * @param  Project  $project
     * @return SiteAuditPlan
     */
    public function plan(User $user, Project $project): SiteAuditPlan
    {
        $account = $project->account;
        $plan = $this->entitlements->for($account);
        $schedules = array_values(array_map(fn (SiteAuditSchedule $schedule): string => $schedule->value,
            array_filter(SiteAuditSchedule::cases(), fn (SiteAuditSchedule $schedule): bool => $schedule->flag() === null || $plan->has($schedule->flag()))));

        return new SiteAuditPlan(
            $this->entitlements->tierFor($account, 'audit')?->name,
            $this->usage->usedThisMonth($account->id),
            $plan->limit('audit.runs.monthly'),
            $plan->limit('audit.competitors'),
            $plan->limit('audit.audits.max'),
            $schedules,
            $user->can('manageService', [$project, 'audit']),
        );
    }

    /**
     * Get the journeys people can choose from.
     *
     * @return list<array{value: string, label: string}>
     */
    public function goals(): array
    {
        return array_map(fn (SiteAuditGoal $goal): array => ['value' => $goal->value, 'label' => $goal->label()], SiteAuditGoal::cases());
    }

    /**
     * Get one audit with its competitors and runs.
     *
     * @param  User  $user
     * @param  SiteAudit  $audit
     * @return SiteAuditDetail
     */
    public function detail(User $user, SiteAudit $audit): SiteAuditDetail
    {
        $journeys = array_map(function (array $journey): array {
            $goal = SiteAuditGoal::tryFrom((string) $journey['key']) ?? SiteAuditGoal::Custom;

            return ['key' => $goal->value, 'label' => $goal === SiteAuditGoal::Custom ? (string) $journey['goal'] : $goal->label(), 'goal' => (string) $journey['goal']];
        }, $audit->journeys);

        return new SiteAuditDetail(
            $audit->id, $audit->name, $audit->url, $journeys, $audit->schedule->value, $audit->next_run_at?->toIso8601String(),
            array_values($audit->competitors()->where('status', 'confirmed')->get()->map(fn (SiteAuditCompetitor $competitor): array => [
                'id' => $competitor->id, 'name' => $competitor->name, 'url' => $competitor->url, 'source' => $competitor->source, 'reason' => $competitor->reason,
            ])->all()),
            array_values($audit->runs()->limit(20)->get()->map(SiteAuditRunSummary::from(...))->all()),
            $this->plan($user, $audit->project),
        );
    }

    /**
     * Get a run's report. While the run is going, the journeys so far and its progress.
     *
     * @param  SiteAuditRun  $run
     * @return SiteAuditReport
     */
    public function report(SiteAuditRun $run): SiteAuditReport
    {
        $audit = $run->audit;
        $sites = [];
        foreach ($run->scores ?? [] as $key => $site) {
            $categories = [];
            foreach (SiteAuditCategory::cases() as $category) {
                if (isset($site['categories'][$category->value])) {
                    $categories[] = ['key' => $category->value, 'label' => $category->label(), 'score' => (int) $site['categories'][$category->value]];
                }
            }
            $sites[] = ['key' => (string) $key, 'name' => (string) $site['name'], 'url' => (string) $site['url'], 'score' => (int) $site['score'], 'categories' => $categories];
        }
        usort($sites, fn (array $a, array $b): int => ($a['key'] === 'site' ? 0 : 1) <=> ($b['key'] === 'site' ? 0 : 1));

        $file = fn (?string $path): ?string => $path === null ? null : route('app.audit.files', ['project' => $run->project_id, 'siteAuditRun' => $run->id, 'file' => basename($path)]);
        $findings = array_values($run->findings()->get()->map(fn (SiteAuditFinding $finding): array => [
            'id' => $finding->id, 'category' => $finding->category->value, 'categoryLabel' => $finding->category->label(),
            'severity' => $finding->severity, 'effort' => $finding->effort, 'title' => $finding->title, 'detail' => $finding->detail,
            'recommendation' => $finding->recommendation, 'pageUrl' => $finding->page_url, 'screenshotUrl' => $file($finding->screenshot_path),
            'boxes' => $finding->boxes ?? [], 'mockupUrl' => $file($finding->mockup_path), 'competitorNote' => $finding->competitor_note,
        ])->all());

        $journeys = array_values($run->journeys()->with(['steps', 'competitor'])->get()->map(function (SiteAuditJourney $journey) use ($audit, $file): array {
            $goal = SiteAuditGoal::tryFrom($journey->goal_key) ?? SiteAuditGoal::Custom;

            return [
                'id' => $journey->id, 'siteKey' => $journey->competitor === null ? 'site' : 'competitor:'.$journey->competitor->id,
                'siteName' => $journey->competitor === null ? $audit->name : $journey->competitor->name, 'siteUrl' => $journey->site_url,
                'goal' => $goal === SiteAuditGoal::Custom ? $journey->goal : $goal->label(), 'outcome' => $journey->outcome->value, 'outcomeLabel' => $journey->outcome->label(),
                'score' => $journey->score, 'stepsCount' => $journey->steps_count, 'seconds' => (int) round($journey->duration_ms / 1000),
                'summary' => $journey->summary, 'friction' => $journey->friction ?? [],
                'steps' => $journey->steps->map(fn (SiteAuditStep $step): array => [
                    'position' => $step->position, 'url' => $step->url, 'action' => $step->action, 'thought' => $step->thought,
                    'screenshotUrl' => $file($step->screenshot_path), 'boxes' => $step->boxes ?? [],
                ])->values()->all(),
            ];
        })->all());

        return new SiteAuditReport(
            SiteAuditRunSummary::from($run), ['id' => $audit->id, 'name' => $audit->name, 'url' => $audit->url], $run->summary,
            ['width' => 1280, 'height' => 800], $sites, $findings, $journeys,
            ['journeys' => count($journeys), 'pages' => $run->pages_visited],
        );
    }
}
