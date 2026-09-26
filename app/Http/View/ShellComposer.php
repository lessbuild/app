<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Queries\AccountSwitcherQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\ProjectSwitcherQuery;
use App\Platform\ServiceRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Builds the Shell for the signed-in layout from the current user and route, so pages don't pass navigation in. */
final class ShellComposer
{
    public function __construct(
        private readonly Request $request,
        private readonly AccountSwitcherQuery $accounts,
        private readonly ProjectSwitcherQuery $projects,
        private readonly ServiceRegistry $services,
    ) {}

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
        ));
    }

    /** @return list<NavLink> */
    private function primaryNav(User $user, Account $account, ?Project $project): array
    {
        $service = $this->request->route('service');
        $links = [new NavLink(__('Projects'), route('dashboard'), ! is_string($service) && ! $this->request->routeIs('account.*', 'settings.*'), 'tasks')];

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

    /** @return array{0: string, 1: list<NavLink>} */
    private function sections(User $user, ?Account $account, ?Project $project): array
    {
        $service = $this->request->route('service');
        $definition = is_string($service) ? $this->services->find($service) : null;

        if ($project !== null && $definition !== null) {
            return [__(':service sections', ['service' => $definition->name()]), array_map(
                fn ($item): NavLink => new NavLink($item->label, $item->url, $this->request->routeIs($item->activePattern)),
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

        if ($account !== null && $this->request->routeIs('account.*')) {
            return [__('Account sections'), $this->accountLinks($user, $account)];
        }

        if ($this->request->routeIs('settings.*')) {
            return [__('Your settings'), [
                new NavLink(__('Profile'), route('settings.profile'), $this->request->routeIs('settings.profile')),
                new NavLink(__('Security'), route('settings.security'), $this->request->routeIs('settings.security')),
                new NavLink(__('Sessions'), route('settings.sessions'), $this->request->routeIs('settings.sessions')),
                new NavLink(__('Privacy'), route('settings.privacy'), $this->request->routeIs('settings.privacy')),
            ]];
        }

        return ['', []];
    }

    /** @return list<NavLink> */
    private function accountLinks(User $user, Account $account): array
    {
        $links = [new NavLink(__('Members'), route('account.members'), $this->request->routeIs('account.members'), 'user')];
        if ($user->can('manageApiTokens', $account)) {
            $links[] = new NavLink(__('API tokens'), route('account.api-tokens'), $this->request->routeIs('account.api-tokens'), 'key');
        }
        if ($user->can('viewAuditLog', $account)) {
            $links[] = new NavLink(__('Audit log'), route('account.audit-log'), $this->request->routeIs('account.audit-log'), 'clock');
        }
        if ($user->can('update', $account)) {
            $links[] = new NavLink(__('Settings'), route('account.settings'), $this->request->routeIs('account.settings'), 'cog');
        }

        return $links;
    }
}
