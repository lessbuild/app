<?php

declare(strict_types=1);

namespace App\Data\Shell;

final readonly class Shell
{
    /**
     * Create a new Shell instance.
     *
     * The frame around every signed-in page: who's signed in, their accounts and projects, the navigation for where
     * they are, and the badges.
     *
     * @param  string  $locale  The language to show the app in.
     * @param  array{id: string, name: string, email: string, isPlatformAdmin: bool}  $user
     * @param  array{id: string, name: string}|null  $account  The current account.
     * @param  list<array{id: string, name: string}>  $accounts  Every account the person belongs to, for the switcher.
     * @param  array{id: string, name: string, isSample: bool}|null  $project  The project the page is in.
     * @param  list<array{id: string, name: string}>  $projects  Projects in the current account, for the switcher.
     * @param  list<NavLink>  $primaryNav  Row one: Projects and the services.
     * @param  string  $sectionLabel  The accessible name of the section navigation.
     * @param  list<NavLink>  $sectionNav  Row two: the current area's pages (may be empty).
     * @param  list<NavLink>  $accountLinks  The account pages, for the user menu.
     * @param  bool  $canCreateProject  Whether to offer "New project".
     * @param  int  $unreadNotifications  The inbox badge.
     * @param  int  $unseenChanges  Changelog entries the person hasn't seen.
     * @param  bool|null  $platformOperational  Whether every part of the platform is working (null when unknown).
     * @param  LimitWarning|null  $limitWarning  A plan allowance nearly used, for people who see billing.
     */
    public function __construct(
        public string $locale,
        public array $user,
        public ?array $account,
        public array $accounts,
        public ?array $project,
        public array $projects,
        public array $primaryNav,
        public string $sectionLabel,
        public array $sectionNav,
        public array $accountLinks,
        public bool $canCreateProject,
        public int $unreadNotifications,
        public int $unseenChanges,
        public ?bool $platformOperational,
        public ?LimitWarning $limitWarning,
    ) {}
}
