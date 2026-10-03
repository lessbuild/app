<?php

declare(strict_types=1);

namespace App\Queries\Shell;

use App\Data\Billing\LimitUsage;
use App\Data\Shell\LimitWarning;
use App\Data\Shell\NavLink;
use App\Data\Shell\Shell;
use App\Models\Account;
use App\Models\Project;
use App\Models\Recipe;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Accounts\AccountSwitcherQuery;
use App\Queries\Notifications\InboxQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use App\Services\Admin\PlatformStatus;
use App\Services\Billing\PlanUsage;
use App\Support\Changelog;
use Illuminate\Support\Facades\Cache;

/** Builds the frame around signed-in pages for the person and the place the app says they're on. */
final class ShellQuery
{
    /**
     * The areas outside projects that have their own section navigation.
     *
     * @var list<string>
     */
    public const AREAS = ['account', 'settings'];

    /**
     * Create a new ShellQuery instance.
     *
     * @param  AccountSwitcherQuery  $accounts  The account switcher's list.
     * @param  ProjectSwitcherQuery  $projects  The project switcher's list.
     * @param  ServiceRegistry  $services  The services for the primary navigation.
     * @param  InboxQuery  $inbox  The unread count for the inbox badge.
     * @param  PlanUsage  $planUsage  Finds a plan allowance that's nearly used up.
     * @param  PlatformStatus  $platformStatus  Says whether the platform is working.
     */
    public function __construct(
        private readonly AccountSwitcherQuery $accounts,
        private readonly ProjectSwitcherQuery $projects,
        private readonly ServiceRegistry $services,
        private readonly InboxQuery $inbox,
        private readonly PlanUsage $planUsage,
        private readonly PlatformStatus $platformStatus,
    ) {}

    /**
     * Build the shell for a person on a page: inside a project (and maybe one of its services), or in an area such as
     * the account's pages or their own settings.
     *
     * @param  User  $user
     * @param  Project|null  $project  only one the person may see
     * @param  string|null  $service  a registered service key, for the service's section navigation
     * @param  string|null  $area  one of AREAS, outside projects
     * @return Shell
     */
    public function handle(User $user, ?Project $project, ?string $service, ?string $area): Shell
    {
        $account = $user->currentAccount;
        $service = $service !== null && $this->services->has($service) ? $service : null;
        [$sectionLabel, $sectionNav] = $this->sections($user, $account, $project, $service, $area);

        return new Shell(
            locale: app()->getLocale(),
            user: ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'isPlatformAdmin' => (bool) $user->is_platform_admin],
            account: $account === null ? null : ['id' => $account->id, 'name' => $account->name],
            accounts: $this->accounts->handle($user),
            project: $project === null ? null : ['id' => $project->id, 'name' => $project->name, 'isSample' => (bool) $project->is_sample],
            projects: $account !== null ? $this->projects->handle($account, 50, $user) : [],
            primaryNav: $account !== null ? $this->primaryNav($user, $account, $project) : [],
            sectionLabel: $sectionLabel,
            sectionNav: $sectionNav,
            accountLinks: $account !== null ? $this->accountLinks($user, $account) : [],
            canCreateProject: $account !== null && $user->can('create', [Project::class, $account]),
            unreadNotifications: $this->inbox->unreadCount($user),
            unseenChanges: Changelog::unseen($user->last_seen_changelog_at?->format('Y-m-d')),
            platformOperational: rescue(fn (): bool => Cache::remember('shell.platform-operational', 60, fn (): bool => $this->platformStatus->snapshot()['operational']), null, false),
            limitWarning: $account !== null && $user->can('viewBilling', $account) ? $this->limitWarning($account) : null,
        );
    }

    /**
     * Build row one: Projects and each service the person may use. Inside a project a service opens that project's
     * service; elsewhere, the service across the account.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  Project|null  $project
     * @return list<NavLink>
     */
    private function primaryNav(User $user, Account $account, ?Project $project): array
    {
        $links = [new NavLink(__('Projects'), route('dashboard', [], false), 'tasks')];
        foreach ($this->services->all() as $definition) {
            if ($user->can('useService', [$account, $definition->key()])) {
                $url = $project !== null ? route('projects.services.show', [$project, $definition->key()], false) : route('services.show', $definition->key(), false);
                $links[] = new NavLink($definition->name(), $url, $definition->icon(), $definition->key());
            }
        }

        return $links;
    }

    /**
     * Build row two: the service's pages inside a project, the project's own pages, the account's pages, or the
     * person's settings.
     *
     * @param  User  $user
     * @param  Account|null  $account
     * @param  Project|null  $project
     * @param  string|null  $service
     * @param  string|null  $area
     * @return array{0: string, 1: list<NavLink>}
     */
    private function sections(User $user, ?Account $account, ?Project $project, ?string $service, ?string $area): array
    {
        $definition = $service !== null ? $this->services->find($service) : null;
        if ($project !== null && $definition !== null) {
            return [__(':service sections', ['service' => $definition->name()]), array_map(
                fn ($item): NavLink => new NavLink($item->label, $this->path($item->url)),
                $definition->navItems($project->id),
            )];
        }
        if ($project !== null) {
            $links = [
                new NavLink(__('Overview'), route('projects.show', $project, false)),
                new NavLink(__('Setup guide'), route('projects.setup', $project, false)),
                new NavLink(__('Domains'), route('projects.domains', $project, false)),
            ];
            if ($user->can('update', $project)) {
                $links[] = new NavLink(__('Settings'), route('projects.settings', $project, false));
            }

            return [__('Project sections'), $links];
        }
        if ($area === 'account' && $account !== null) {
            return [__('Account sections'), $this->accountLinks($user, $account)];
        }
        if ($area === 'settings') {
            return [__('Your settings'), [
                new NavLink(__('Profile'), route('settings.profile', [], false)),
                new NavLink(__('Security'), route('settings.security', [], false)),
                new NavLink(__('Sessions'), route('settings.sessions', [], false)),
                new NavLink(__('Notifications'), route('settings.notifications', [], false)),
                new NavLink(__('Privacy'), route('settings.privacy', [], false)),
            ]];
        }

        return ['', []];
    }

    /**
     * Build the links to the account pages the person may open, for the user menu and the account section.
     *
     * @param  User  $user
     * @param  Account  $account
     * @return list<NavLink>
     */
    private function accountLinks(User $user, Account $account): array
    {
        $links = [
            new NavLink(__('Ask'), route('assistant', [], false), 'information-circle'),
            new NavLink(__('Members'), route('account.members', [], false), 'user'),
        ];
        if ($user->can('manageApiTokens', $account)) {
            $links[] = new NavLink(__('API tokens'), route('account.api-tokens', [], false), 'key');
        }
        if ($user->can('viewBilling', $account)) {
            $links[] = new NavLink(__('Billing'), route('account.billing', [], false), 'tasks');
        }
        if ($user->can('viewAuditLog', $account)) {
            $links[] = new NavLink(__('Audit log'), route('account.audit-log', [], false), 'clock');
        }
        if ($user->can('viewAny', Recipe::class)) {
            $links[] = new NavLink(__('Recipes'), route('account.recipes', [], false), 'code');
        }
        if ($user->can('update', $account)) {
            $links[] = new NavLink(__('Providers'), route('account.providers', [], false), 'server');
            $links[] = new NavLink(__('Clients'), route('account.clients', [], false), 'users');
            $links[] = new NavLink(__('Webhooks'), route('account.webhooks', [], false), 'bell');
            $links[] = new NavLink(__('Security'), route('account.security', [], false), 'finger-print');
            $links[] = new NavLink(__('Settings'), route('account.settings', [], false), 'cog');
        }

        return $links;
    }

    /**
     * Describe the plan allowance the account is closest to running out of, if it's used 80% or more of one.
     *
     * @param  Account  $account
     * @return LimitWarning|null
     */
    private function limitWarning(Account $account): ?LimitWarning
    {
        $near = Cache::remember("plan-usage.nearest.{$account->id}", 300, fn (): ?LimitUsage => $this->planUsage->nearest($account));
        if (! $near instanceof LimitUsage) {
            return null;
        }
        $monthly = $near->monthly ? __(' this month') : '';
        $message = $near->percent() >= 100
            ? __('You’ve reached your plan’s limit of :limit :label:monthly.', ['limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $monthly])
            : __('You’ve used :used of :limit :label on your plan:monthly.', ['used' => number_format($near->used), 'limit' => number_format((int) $near->limit), 'label' => $near->label, 'monthly' => $monthly]);
        $upgrade = $this->planUsage->upgradeFor($near);
        if ($upgrade !== null) {
            $message .= ' '.__(':service :tier gives :limit :label for $:price a month.', [
                'service' => $upgrade['service'], 'tier' => $upgrade['tier']->name, 'limit' => $upgrade['limit'] === null ? __('unlimited') : number_format($upgrade['limit']),
                'label' => $near->label, 'price' => number_format($upgrade['monthlyCents'] / 100, $upgrade['monthlyCents'] % 100 === 0 ? 0 : 2),
            ]);
        }

        return new LimitWarning(
            $near->percent() >= 100 ? 'danger' : 'warning',
            $message,
            $upgrade !== null ? __('Upgrade') : __('See plans'),
            route('account.billing', ['tab' => $near->service], false),
        );
    }

    /**
     * Turn a URL a service built with route() into a path.
     *
     * @param  string  $url
     * @return string
     */
    private function path(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
