<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;

/**
 * Everything the Signal two-row topbar shows around a page. Row one: brand, the platform's areas
 * (Projects and each service), account switcher and user menu. Row two: the project switcher and
 * the sections of the area you are in.
 */
final readonly class Shell
{
    /**
     * @param  list<array{id: string, name: string}>  $accounts
     * @param  list<array{id: string, name: string}>  $projects  projects in the current account, for the switcher
     * @param  list<NavLink>  $primaryNav  row one: Projects and the services
     * @param  list<NavLink>  $sectionNav  row two: sections of the current area (may be empty)
     * @param  list<NavLink>  $accountLinks  account pages for the user menu
     */
    public function __construct(
        public User $user,
        public ?Account $account,
        public array $accounts,
        public ?Project $project,
        public array $projects,
        public array $primaryNav,
        public string $sectionLabel,
        public array $sectionNav,
        public array $accountLinks,
        public bool $canCreateProject,
        public int $unreadNotifications = 0,
    ) {}
}
