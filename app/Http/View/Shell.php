<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;

/** Everything the signed-in frame shows around a page: switchers and the sidebar. */
final readonly class Shell
{
    /**
     * @param  list<array{id: string, name: string}>  $accounts
     * @param  list<array{id: string, name: string}>  $projects  projects in the current account, for the switcher
     * @param  list<NavLink>  $projectNav  empty outside a project
     * @param  list<NavLink>  $accountNav
     */
    public function __construct(
        public User $user,
        public ?Account $account,
        public array $accounts,
        public ?Project $project,
        public array $projects,
        public array $projectNav,
        public array $accountNav,
        public bool $canCreateProject,
    ) {}
}
