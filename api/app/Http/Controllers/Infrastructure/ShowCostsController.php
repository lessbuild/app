<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerCost;
use App\Models\Project;
use App\Models\ProviderBill;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\CloudBillsQuery;
use App\Queries\Infrastructure\InfrastructureCostsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Infrastructure\ServerRightsizing;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowCostsController
{
    /**
     * Show what the account's servers cost a month (from the providers' price lists), the providers' actual bills,
     * right-sizing suggestions and the monthly budget.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  InfrastructureCostsQuery  $costs
     * @param  ServerRightsizing  $rightsizing
     * @param  CloudBillsQuery  $bills
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, InfrastructureCostsQuery $costs, ServerRightsizing $rightsizing, CloudBillsQuery $bills): JsonResponse
    {
        $report = $costs->handle($project->account_id);
        $money = fn (?ProviderBill $bill): ?array => $bill === null ? null : ['amount' => (float) $bill->amount, 'currency' => (string) $bill->currency];

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'accountName' => $project->account->name,
            'totals' => $report['totals'],
            'unknown' => $report['unknown'],
            'idle' => $report['idle'],
            'rows' => $report['rows']->map(fn (ServerCost $row): array => [
                'serverId' => $row->server->id,
                'server' => $row->server->label(),
                'provider' => $row->server->provider?->type->label(),
                'imported' => $row->server->provider_id === null,
                'size' => $row->server->size,
                'websites' => $row->websites,
                'attribution' => $row->attribution(),
                'projects' => $row->projects,
                'averageCpu' => $row->averageCpu,
                'monthly' => $row->monthly,
                'currency' => $row->server->monthly_cost_currency ?? 'USD',
                'entered' => $row->server->monthly_cost_source === 'manual',
                'enteredCost' => $row->server->monthly_cost,
                'idle' => $row->idle,
            ])->values(),
            'bills' => array_map(fn (array $row): array => [
                'provider' => $row['provider']->name,
                'type' => $row['provider']->type->label(),
                'error' => $row['provider']->billing_error,
                'checked' => $row['provider']->billing_checked_at !== null,
                'estimate' => $row['estimate'],
                'previous' => $money($row['previous']),
                'difference' => $row['difference'],
                'current' => $money($row['current']),
            ], $bills->handle($project->account_id)),
            'rightsizing' => array_map(fn (array $row): array => [
                ...$row,
                'server' => ['id' => $row['server']->id, 'name' => $row['server']->name],
                // Prices are in the currency the server's provider bills in.
                'currency' => $row['server']->provider !== null ? ServerPricing::currency($row['server']->provider->type) : 'USD',
            ], $rightsizing->suggestions(
                Server::query()->where('account_id', $project->account_id)->where('provisioning_status', Server::STATUS_ACTIVE)->with('provider')->get(),
            )),
            'rightsizingDays' => ServerRightsizing::DAYS,
            'budget' => $project->account->monthly_infrastructure_budget,
            'canManage' => $user->can('create', [Server::class, $project]),
            'canBudget' => $user->can('manageCosts', [Server::class, $project]),
        ]);
    }
}
