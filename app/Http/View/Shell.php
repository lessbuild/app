<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Data\Billing\LimitUsage;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;

/**
 * Everything the Signal two-row topbar shows around a page. Row one: brand, the platform's areas
 * (Projects and each service), account switcher and user menu. Row two: the project switcher and
 * the sections of the area you are in.
 */
final readonly class Shell
{
    /**
     * Create a new Shell instance.
     *
     * Everything the signed-in layout's navigation needs.
     *
     * @param  User  $user  The signed-in person.
     * @param  ?Account  $account  Their current account, if any.
     * @param  list<array{id: string, name: string}>  $accounts
     * @param  ?Project  $project  The project the page is in, if any.
     * @param  list<array{id: string, name: string}>  $projects  projects in the current account, for the switcher
     * @param  list<NavLink>  $primaryNav  row one: Projects and the services
     * @param  string  $sectionLabel  The accessible name of the section navigation.
     * @param  list<NavLink>  $sectionNav  row two: sections of the current area (may be empty)
     * @param  list<NavLink>  $accountLinks  account pages for the user menu
     * @param  bool  $canCreateProject  Whether to offer "New project".
     * @param  int  $unreadNotifications  The inbox badge count.
     * @param  LimitUsage|null  $limitWarning  A plan limit the account has used 80% or more of, for people who see billing.
     * @param  int  $unseenChanges  How many changelog entries the person hasn't seen yet.
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
        public ?LimitUsage $limitWarning = null,
        public int $unseenChanges = 0,
    ) {}
}
