<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\AlertRuleSummary;
use App\Models\AlertDestination;
use App\Models\AlertEscalation;
use App\Models\AlertRule;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowAlertRuleController
{
    /**
     * Show an alert rule: its latest value, its incidents, where its alerts go, and its escalation steps.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertRule  $rule
     * @param  ProjectOverviewQuery  $overview
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertRule $rule, ProjectOverviewQuery $overview, Entitlements $entitlements): JsonResponse
    {
        $observation = $rule->observation ?? [];
        $routes = $rule->destinations()->get();
        $pivot = $routes->first()?->getRelation('pivot');
        $limit = $entitlements->for($project->account)->limit('monitoring.escalation_steps.max');
        // Where alerts go is for the people who can change it; viewers see the rule and its incidents only.
        $canManage = $user->can('update', $rule);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'rule' => [
                ...(array) AlertRuleSummary::from($rule),
                'version' => $rule->state_version,
                'archived' => $rule->trashed(),
                'value' => array_key_exists('value', $observation) && is_numeric($observation['value']) ? round((float) $observation['value'], 3) : null,
                'samples' => array_key_exists('value', $observation) ? (int) ($observation['samples'] ?? 0) : null,
                'checkedAt' => $rule->checked_at?->toIso8601String(),
            ],
            'incidents' => $rule->incidents()->latest('opened_at')->latest('id')->limit(10)->get()->map(fn (Incident $incident): array => [
                'id' => $incident->id, 'title' => $incident->title, 'statusLabel' => __($incident->statusLabel()), 'openedAt' => $incident->opened_at->toIso8601String(),
            ])->values(),
            'destinations' => ! $canManage ? [] : AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get()
                ->map(fn (AlertDestination $destination): array => ['id' => $destination->id, 'name' => $destination->name, 'type' => $destination->type->label()])->values(),
            'routes' => $canManage ? $routes->modelKeys() : [],
            'notifyOpened' => (bool) ($pivot?->getAttribute('opened') ?? true),
            'notifyRecovered' => (bool) ($pivot?->getAttribute('recovered') ?? true),
            'escalations' => ! $canManage ? [] : $rule->escalations()->get()->map(fn (AlertEscalation $step): array => ['destinationId' => $step->alert_destination_id, 'delayMinutes' => $step->delay_minutes])->values(),
            'escalationSteps' => $limit === null ? 10 : min(10, $limit),
            'canManage' => $canManage,
        ]);
    }
}
