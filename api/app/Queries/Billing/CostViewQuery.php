<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Data\Billing\BillingOverview;
use App\Models\Account;
use App\Models\EnabledService;
use App\Models\Project;
use App\Models\ProviderBill;
use App\Models\Server;
use App\Models\Website;

/**
 * Puts what BuildPusher charges and what the servers cost side by side for each project. Each service's monthly
 * charge (its plan plus usage past the allowance) is split evenly across the projects that use the service; add-ons
 * are split across all projects. A server's cost goes to the projects with websites on it, split evenly when shared,
 * and a server no project uses is listed as unassigned.
 */
final class CostViewQuery
{
    /**
     * Build the view.
     *
     * @param  Account  $account
     * @param  BillingOverview  $overview  the account's plans and usage this month
     * @return array{currency: string, projects: list<array{project: Project, platform: float, cloud: array<string, float>}>, unassigned: array<string, float>, unpriced: int, platform_total: float, cloud_total: array<string, float>, billed: array<string, float>}
     */
    public function handle(Account $account, BillingOverview $overview): array
    {
        $projects = Project::query()->where('account_id', $account->id)->where('is_sample', false)->orderBy('name')->get();
        $ids = $projects->modelKeys();
        $enabled = EnabledService::query()->whereIn('project_id', $ids)->get()->groupBy('service')->map(fn ($rows): array => $rows->pluck('project_id')->unique()->values()->all());

        $platform = array_fill_keys($ids, 0.0);
        $serviceCents = 0;
        foreach ($overview->services as $service) {
            $cents = ($service->tier->monthlyCents ?? 0) + array_sum(array_map(fn ($meter): int => $meter->overageCents, $service->meters));
            $serviceCents += $cents;
            $users = $enabled->get($service->key, []);
            foreach ($users as $projectId) {
                $platform[$projectId] += $cents / 100 / count($users);
            }
        }
        // Add-ons, and services no project uses, are shared by every project.
        $shared = $overview->monthlyTotalCents / 100 - array_sum($platform);
        if ($ids !== [] && $shared > 0.004) {
            foreach ($ids as $projectId) {
                $platform[$projectId] += $shared / count($ids);
            }
        }

        $cloud = array_fill_keys($ids, []);
        $unassigned = [];
        $unpriced = 0;
        $servers = Server::query()->where('account_id', $account->id)->get();
        $usedBy = Website::query()->whereIn('server_id', $servers->modelKeys())->whereNotNull('environment_id')->with('environment:id,project_id')->get()
            ->groupBy('server_id')->map(fn ($websites): array => $websites->map(fn (Website $website): ?string => $website->environment?->project_id)->filter()->unique()->values()->all());
        foreach ($servers as $server) {
            if ($server->monthly_cost === null) {
                $unpriced++;

                continue;
            }
            $currency = $server->monthly_cost_currency ?? 'USD';
            $owners = array_values(array_intersect($usedBy->get($server->id, []), $ids));
            if ($owners === []) {
                $unassigned[$currency] = round(($unassigned[$currency] ?? 0) + $server->monthly_cost, 2);

                continue;
            }
            foreach ($owners as $projectId) {
                $cloud[$projectId][$currency] = ($cloud[$projectId][$currency] ?? 0) + $server->monthly_cost / count($owners);
            }
        }

        $rows = [];
        $cloudTotal = $unassigned;
        foreach ($projects as $project) {
            $costs = array_map(fn (float $amount): float => round($amount, 2), $cloud[$project->id]);
            foreach ($costs as $currency => $amount) {
                $cloudTotal[$currency] = round(($cloudTotal[$currency] ?? 0) + $amount, 2);
            }
            $rows[] = ['project' => $project, 'platform' => round($platform[$project->id], 2), 'cloud' => $costs];
        }
        ksort($cloudTotal);
        $billed = ProviderBill::query()->whereHas('provider', fn ($query) => $query->where('account_id', $account->id))
            ->where('period', now()->subMonthNoOverflow()->format('Y-m'))->where('final', true)
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')->map(fn ($total): float => round((float) $total, 2))->all();

        return [
            'currency' => strtoupper($overview->currency), 'projects' => $rows, 'unassigned' => $unassigned, 'unpriced' => $unpriced,
            'platform_total' => round($overview->monthlyTotalCents / 100, 2), 'cloud_total' => $cloudTotal, 'billed' => $billed,
        ];
    }
}
