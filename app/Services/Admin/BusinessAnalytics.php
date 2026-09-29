<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\BillingSelection;
use App\Models\Build;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\SignInEvent;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The platform's business at a glance: people, accounts, paying accounts and estimated revenue from the chosen tiers
 * and add-ons, churn, each service's tier mix, and 30 days of sign-ups, deploys and monitoring checks. Cached for five
 * minutes, since it scans every account. The sign-up funnel follows the last 30 days' new accounts from a verified email
 * to a paid plan.
 */
final class BusinessAnalytics
{
    /**
     * Create a new BusinessAnalytics instance.
     *
     * Summarises the business.
     *
     * @param  ServiceRegistry  $services  Gives each service's catalogue, for prices and tier names.
     */
    public function __construct(private readonly ServiceRegistry $services) {}

    /**
     * Get the summary, from the cache when it's under five minutes old.
     *
     * @return array{totals: array{users: int, active_users: int, accounts: int, paid_accounts: int, signups: int, mrr_cents: int, churned: int}, services: array<string, array{name: string, tiers: array<string, array{name: string, accounts: int}>}>, trend: list<array{date: string, signups: int, deploys: int, checks: int}>, generated_at: string}
     */
    public function snapshot(): array
    {
        /** @var array{totals: array{users: int, active_users: int, accounts: int, paid_accounts: int, signups: int, mrr_cents: int, churned: int}, services: array<string, array{name: string, tiers: array<string, array{name: string, accounts: int}>}>, trend: list<array{date: string, signups: int, deploys: int, checks: int}>, funnel: list<array{label: string, accounts: int}>, generated_at: string} */
        return Cache::remember('admin:business-analytics', 300, fn (): array => $this->compute());
    }

    /**
     * Work the summary out from the database.
     *
     * @return array{totals: array{users: int, active_users: int, accounts: int, paid_accounts: int, signups: int, mrr_cents: int, churned: int}, services: array<string, array{name: string, tiers: array<string, array{name: string, accounts: int}>}>, trend: list<array{date: string, signups: int, deploys: int, checks: int}>, generated_at: string}
     */
    private function compute(): array
    {
        $start = CarbonImmutable::now()->startOfDay()->subDays(29);
        $active = BillingSelection::query()->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
        $accounts = Account::query()->count();
        $mrr = 0;
        $paid = [];
        $services = [];
        foreach ($this->services->all() as $service) {
            $billing = $service->billing();
            $tiers = [];
            foreach ($billing->tiers as $tier) {
                $tiers[$tier->key] = ['name' => $tier->name, 'accounts' => 0];
            }
            $chosen = $active->where('service', $service->key());
            foreach ($chosen as $selection) {
                if ($selection->kind === SelectionKind::Tier) {
                    $tier = $billing->tier($selection->item_key);
                    if ($tier !== null && isset($tiers[$tier->key])) {
                        $tiers[$tier->key]['accounts']++;
                        $mrr += (int) $tier->monthlyCents * max(1, $selection->quantity);
                        if (! $tier->isFree()) {
                            $paid[$selection->account_id] = true;
                        }
                    }
                } else {
                    $mrr += (int) $billing->addOn($selection->item_key)?->monthlyCentsPerUnit * $selection->quantity;
                }
            }
            // Accounts that never chose a tier are on the free default.
            $default = $billing->defaultTier()->key;
            if (isset($tiers[$default])) {
                $tiers[$default]['accounts'] += $accounts - $chosen->where('kind', SelectionKind::Tier)->pluck('account_id')->unique()->count();
            }
            $services[$service->key()] = ['name' => $service->name(), 'tiers' => $tiers];
        }

        return [
            'totals' => [
                'users' => User::query()->count(),
                'active_users' => SignInEvent::query()->where('succeeded', true)->where('created_at', '>=', $start)->distinct()->count('user_id'),
                'accounts' => $accounts,
                'paid_accounts' => count($paid),
                'signups' => User::query()->where('created_at', '>=', $start)->count(),
                'mrr_cents' => $mrr,
                'churned' => BillingSelection::query()->where('kind', SelectionKind::Tier)->whereBetween('ends_at', [$start, now()])->distinct()->count('account_id'),
            ],
            'services' => $services,
            'trend' => $this->trend($start),
            'funnel' => $this->funnel($start, array_keys($paid)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Follow the accounts created since the start through setup: how many reached each step, in order. Sample
     * projects don't count; a step counts once the account has done it, whatever order it went in.
     *
     * @param  CarbonImmutable  $start
     * @param  list<string>  $paidAccountIds  accounts on a paid tier now
     * @return list<array{label: string, accounts: int}>
     */
    private function funnel(CarbonImmutable $start, array $paidAccountIds): array
    {
        $realProjects = fn () => Project::query()->where('is_sample', false);
        $steps = [
            __('Signed up') => fn (Builder $query) => $query,
            __('Verified their email') => fn (Builder $query) => $query->whereHas('members', fn ($members) => $members->whereNotNull('email_verified_at')),
            __('Created a project') => fn (Builder $query) => $query->whereIn('id', $realProjects()->select('account_id')),
            __('Connected a provider') => fn (Builder $query) => $query->whereIn('id', Provider::query()->select('account_id')),
            __('Created a server') => fn (Builder $query) => $query->whereIn('id', Provider::query()->whereIn('id', Server::query()->select('provider_id'))->select('account_id')),
            __('Connected a repository') => fn (Builder $query) => $query->whereIn('id', $realProjects()->whereIn('id', Repository::withTrashed()->select('project_id'))->select('account_id')),
            __('Deployed') => fn (Builder $query) => $query->whereIn('id', $realProjects()->whereIn('id', Repository::withTrashed()->whereIn('id', Build::query()->where('status', Build::STATUS_SUCCEEDED)->select('repository_id'))->select('project_id'))->select('account_id')),
            __('Added a monitor') => fn (Builder $query) => $query->whereIn('id', $realProjects()->whereIn('id', DB::table('environments')->whereIn('id', Monitor::withTrashed()->select('environment_id'))->select('project_id'))->select('account_id')),
            __('Received analytics visits') => fn (Builder $query) => $query->whereIn('id', $realProjects()->whereIn('id', AnalyticsSite::query()->whereNotNull('last_event_at')->select('project_id'))->select('account_id')),
            __('Pays') => fn (Builder $query) => $query->whereIn('id', $paidAccountIds),
        ];
        $funnel = [];
        foreach ($steps as $label => $step) {
            $funnel[] = ['label' => (string) $label, 'accounts' => $step(Account::query()->where('created_at', '>=', $start))->count()];
        }

        return $funnel;
    }

    /**
     * Count sign-ups, deploys and monitoring checks per day for the 30 days from the start.
     *
     * @param  CarbonImmutable  $start
     * @return list<array{date: string, signups: int, deploys: int, checks: int}>
     */
    private function trend(CarbonImmutable $start): array
    {
        $daily = fn (string $table): array => DB::table($table)->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->groupByRaw('DATE(created_at)')->pluck('total', 'day')->all();
        [$signups, $deploys, $checks] = [$daily((new User)->getTable()), $daily((new Build)->getTable()), $daily((new MonitorCheck)->getTable())];
        $days = [];
        for ($offset = 0; $offset < 30; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $days[] = ['date' => $day, 'signups' => (int) ($signups[$day] ?? 0), 'deploys' => (int) ($deploys[$day] ?? 0), 'checks' => (int) ($checks[$day] ?? 0)];
        }

        return $days;
    }
}
