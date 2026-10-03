// The JSON Laravel's /api/app/shell returns.

export type NavLink = { label: string; url: string; icon: string | null; service: string | null };

export type Shell = {
    locale: string;
    user: { id: string; name: string; email: string; isPlatformAdmin: boolean };
    account: { id: string; name: string } | null;
    accounts: Array<{ id: string; name: string }>;
    project: { id: string; name: string; isSample: boolean } | null;
    projects: Array<{ id: string; name: string }>;
    primaryNav: NavLink[];
    sectionLabel: string;
    sectionNav: NavLink[];
    accountLinks: NavLink[];
    canCreateProject: boolean;
    unreadNotifications: number;
    unseenChanges: number;
    platformOperational: boolean | null;
    limitWarning: { tone: 'warning' | 'danger'; message: string; linkLabel: string; url: string } | null;
};
