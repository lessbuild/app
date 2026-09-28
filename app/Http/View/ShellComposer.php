<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Models\Account;
use App\Models\Project;
use App\Models\Recipe;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Queries\Accounts\AccountSwitcherQuery;
use App\Queries\Notifications\InboxQuery;
use App\Queries\Projects\ProjectSwitcherQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Builds the Shell for the signed-in layout from the current user and route, so pages don't pass navigation in. */
final class ShellComposer
{
    /**
     * Create a new ShellComposer instance.
     *
     * Builds the navigation shell around signed-in pages.
     *
     * @param  Request  $request  The route decides which section and link are current.
     * @param  AccountSwitcherQuery  $accounts  The account switcher's list.
     * @param  ProjectSwitcherQuery  $projects  The project switcher's list.
     * @param  ServiceRegistry  $services  The services for the primary navigation.
     * @param  InboxQuery  $inbox  The unread count for the inbox badge.
     */
    public function __construct(
        private readonly Request $request,
        private readonly AccountSwitcherQuery $accounts,
        private readonly ProjectSwitcherQuery $projects,
        private readonly ServiceRegistry $services,
        private readonly InboxQuery $inbox,
    ) {}

    /**
     * The admin panel's pages, as label and route name, in the order its navigation shows them.
     *
     * @var list<array{string, string}>
     */
    private const ADMIN_SECTIONS = [['Overview', 'admin.home'], ['Health', 'admin.health'], ['Queues', 'admin.queues'], ['Business', 'admin.analytics'], ['Customers', 'admin.customers'], ['Access requests', 'admin.access-requests']];

    /**
     * Give the layout its shell: switchers, primary and section navigation, account links and the unread count. Guests
     * get nothing.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $user = $this->request->user();
        if (! $user instanceof User) {
            return;
        }

        $account = $user->currentAccount;
        $project = $this->request->route('project');
        $project = $project instanceof Project && $user->can('view', $project) ? $project : null;
        [$sectionLabel, $sectionNav] = $this->sections($user, $account, $project);

        $view->with('shell', new Shell(
            user: $user,
            account: $account,
            accounts: $this->accounts->handle($user),
            project: $project,
            projects: $account !== null ? $this->projects->handle($account) : [],
            primaryNav: $account !== null ? $this->primaryNav($user, $account, $project) : [],
            sectionLabel: $sectionLabel,
            sectionNav: $sectionNav,
            accountLinks: $account !== null ? $this->accountLinks($user, $account) : [],
            canCreateProject: $account !== null && $user->can('create', [Project::class, $account]),
            unreadNotifications: $this->inbox->unreadCount($user),
        ));
    }

    /**
     * Build row one of the navigation: Projects and each service the person may use. Inside a project a service opens
     * that project's service; elsewhere, the service across the account.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  Project|null  $project
     * @return list<NavLink>
     */
    private function primaryNav(User $user, Account $account, ?Project $project): array
    {
        $service = $this->currentService();
        $links = [new NavLink(__('Projects'), route('dashboard'), ! is_string($service) && $this->request->routeIs('dashboard', 'projects.*'), 'tasks')];

        foreach ($this->services->all() as $definition) {
            if (! $user->can('useService', [$account, $definition->key()])) {
                continue;
            }
            // Inside a project a service tab opens that project's service; elsewhere, the service across the account.
            $url = $project !== null
                ? route('projects.services.show', [$project, $definition->key()])
                : route('services.show', $definition->key());
            $links[] = new NavLink($definition->name(), $url, $service === $definition->key(), $definition->icon());
        }

        return $links;
    }

    /**
     * Build row two of the navigation: the current service's pages inside a project, the project's own pages, account
     * pages, or personal settings, depending on where the person is.
     *
     * @param  User  $user
     * @param  Account|null  $account
     * @param  Project|null  $project
     * @return array{0: string, 1: list<NavLink>}
     */
    private function sections(User $user, ?Account $account, ?Project $project): array
    {
        $service = $this->currentService();
        $definition = is_string($service) ? $this->services->find($service) : null;

        if ($project !== null && $definition !== null) {
            return [__(':service sections', ['service' => $definition->name()]), array_map(
                fn ($item): NavLink => new NavLink($item->label, $item->url, $this->request->routeIs(...explode('|', $item->activePattern))),
                $definition->navItems($project->id),
            )];
        }

        if ($project !== null) {
            $links = [
                new NavLink(__('Overview'), route('projects.show', $project), $this->request->routeIs('projects.show')),
                new NavLink(__('Domains'), route('projects.domains', $project), $this->request->routeIs('projects.domains')),
            ];
            if ($user->can('update', $project)) {
                $links[] = new NavLink(__('Settings'), route('projects.settings', $project), $this->request->routeIs('projects.settings'));
            }

            return [__('Project sections'), $links];
        }

        if ($user->is_platform_admin && $this->request->routeIs('admin.*')) {
            return [__('Admin sections'), array_map(
                fn (array $link): NavLink => new NavLink(__($link[0]), route($link[1]), $this->request->routeIs($link[1].'*')),
                self::ADMIN_SECTIONS,
            )];
        }

        if ($account !== null && $this->request->routeIs('account.*', 'recipes.*')) {
            return [__('Account sections'), $this->accountLinks($user, $account)];
        }

        if ($this->request->routeIs('settings.*')) {
            return [__('Your settings'), [
                new NavLink(__('Profile'), route('settings.profile'), $this->request->routeIs('settings.profile')),
                new NavLink(__('Security'), route('settings.security'), $this->request->routeIs('settings.security')),
                new NavLink(__('Sessions'), route('settings.sessions'), $this->request->routeIs('settings.sessions')),
                new NavLink(__('Notifications'), route('settings.notifications'), $this->request->routeIs('settings.notifications')),
                new NavLink(__('Privacy'), route('settings.privacy'), $this->request->routeIs('settings.privacy')),
            ]];
        }

        return ['', []];
    }

    /**
     * Work out which service's pages are showing: the generic {service} pages, or a service's own routes (e.g.
     * analytics.*).
     *
     * @return string|null
     */
    private function currentService(): ?string
    {
        $service = $this->request->route('service');
        if (is_string($service)) {
            return $service;
        }
        foreach ($this->services->keys() as $key) {
            if ($this->request->routeIs($key.'.*')) {
                return $key;
            }
        }

        return null;
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
        $links = [new NavLink(__('Members'), route('account.members'), $this->request->routeIs('account.members'), 'user')];
        if ($user->can('manageApiTokens', $account)) {
            $links[] = new NavLink(__('API tokens'), route('account.api-tokens'), $this->request->routeIs('account.api-tokens'), 'key');
        }
        if ($user->can('viewBilling', $account)) {
            $links[] = new NavLink(__('Billing'), route('account.billing'), $this->request->routeIs('account.billing'), 'tasks');
        }
        if ($user->can('viewAuditLog', $account)) {
            $links[] = new NavLink(__('Audit log'), route('account.audit-log'), $this->request->routeIs('account.audit-log'), 'clock');
        }
        if ($user->can('viewAny', Recipe::class)) {
            $links[] = new NavLink(__('Recipes'), route('account.recipes'), $this->request->routeIs('account.recipes*', 'recipes.gallery*'), 'code');
        }
        if ($user->can('update', $account)) {
            $links[] = new NavLink(__('Providers'), route('account.providers'), $this->request->routeIs('account.providers*'), 'server');
            $links[] = new NavLink(__('Settings'), route('account.settings'), $this->request->routeIs('account.settings'), 'cog');
        }

        return $links;
    }
}
