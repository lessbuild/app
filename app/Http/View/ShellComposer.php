<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Queries\AccountSwitcherQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Queries\ProjectOverviewQuery;
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
        private readonly ProjectOverviewQuery $overview,
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

        $view->with('shell', new Shell(
            user: $user,
            account: $account,
            accounts: $this->accounts->handle($user),
            project: $project,
            projects: $account !== null ? $this->projects->handle($account) : [],
            projectNav: $project !== null ? $this->projectNav($user, $project) : [],
            accountNav: $account !== null ? $this->accountNav($user, $account) : [],
            canCreateProject: $account !== null && $user->can('create', [Project::class, $account]),
        ));
    }

    /** @return list<NavLink> */
    private function projectNav(User $user, Project $project): array
    {
        $overview = $this->overview->handle($project, $user);
        $links = [new NavLink(__('Overview'), route('projects.show', $project), $this->request->routeIs('projects.show'), 'view-grid')];

        foreach ($overview->enabledServices() as $card) {
            $service = $this->services->find($card->key);
            if ($service === null || ! $card->canUse) {
                continue;
            }
            $inService = $this->request->route('service') === $card->key;
            $children = array_map(
                fn ($item): NavLink => new NavLink($item->label, $item->url, $inService && $this->request->routeIs($item->activePattern)),
                $service->navItems($project->id),
            );
            // A service with a single page is one link; with several, its pages sit under it.
            $links[] = count($children) === 1
                ? new NavLink($card->name, $children[0]->url, $children[0]->current, $card->icon)
                : new NavLink($card->name, $children[0]->url, false, $card->icon, $children);
        }

        if ($overview->canManage) {
            $links[] = new NavLink(__('Add a service'), route('projects.show', $project).'#services-heading', false, 'plus-circle');
            $links[] = new NavLink(__('Settings'), route('projects.settings', $project), $this->request->routeIs('projects.settings'), 'cog');
        }

        return $links;
    }

    /** @return list<NavLink> */
    private function accountNav(User $user, Account $account): array
    {
        $links = [
            new NavLink(__('Projects'), route('dashboard'), $this->request->routeIs('dashboard', 'projects.create'), 'tasks'),
            new NavLink(__('Members'), route('account.members'), $this->request->routeIs('account.members'), 'user'),
        ];
        if ($user->can('manageApiTokens', $account)) {
            $links[] = new NavLink(__('API tokens'), route('account.api-tokens'), $this->request->routeIs('account.api-tokens'), 'key');
        }
        if ($user->can('viewAuditLog', $account)) {
            $links[] = new NavLink(__('Audit log'), route('account.audit-log'), $this->request->routeIs('account.audit-log'), 'clock');
        }
        if ($user->can('update', $account)) {
            $links[] = new NavLink(__('Account settings'), route('account.settings'), $this->request->routeIs('account.settings'), 'cog');
        }

        return $links;
    }
}
