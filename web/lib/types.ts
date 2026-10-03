// The JSON Laravel's /api/app returns, as the Next.js app reads it.

export type NavLink = { label: string; url: string; icon: string | null };

export type Shell = {
    locale: string;
    user: { name: string; email: string; isPlatformAdmin: boolean };
    account: { id: string; name: string } | null;
    accounts: Array<{ id: string; name: string }>;
    project: { id: string; name: string } | null;
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
    links: Record<'dashboard' | 'settings' | 'help' | 'changelog' | 'notifications' | 'logout' | 'status', string>;
};

export type RunStatus = 'queued' | 'running' | 'done' | 'failed';

export type RunSummary = {
    id: number;
    status: RunStatus;
    trigger: 'manual' | 'scheduled';
    score: number | null;
    createdAt: string;
    finishedAt: string | null;
    error: string | null;
};

export type AuditPlan = {
    tier: string | null;
    runsUsed: number;
    runsAllowance: number | null;
    competitorLimit: number | null;
    auditLimit: number | null;
    schedules: string[];
    canManage: boolean;
};

export type Goal = { value: string; label: string };

export type AuditListItem = { id: number; name: string; url: string; schedule: string; competitors: number; latestRun: RunSummary | null };

export type Competitor = { id?: number; name: string; url: string; source?: string; reason: string | null };

export type AuditDetail = {
    id: number;
    name: string;
    url: string;
    journeys: Array<{ key: string; label: string; goal: string }>;
    schedule: string;
    nextRunAt: string | null;
    competitors: Competitor[];
    runs: RunSummary[];
    plan: AuditPlan;
};

export type Box = { x: number; y: number; width: number; height: number };

export type Finding = {
    id: number;
    category: string;
    categoryLabel: string;
    severity: 'high' | 'medium' | 'low';
    effort: 'small' | 'medium' | 'large';
    title: string;
    detail: string;
    recommendation: string;
    pageUrl: string | null;
    screenshotUrl: string | null;
    boxes: Box[];
    mockupUrl: string | null;
    competitorNote: string | null;
};

export type Step = {
    position: number;
    url: string;
    action: { type: string; label?: string; text?: string; direction?: string };
    thought: string | null;
    screenshotUrl: string | null;
    boxes: Box[];
};

export type Journey = {
    id: number;
    siteKey: string;
    siteName: string;
    siteUrl: string;
    goal: string;
    outcome: 'succeeded' | 'struggled' | 'failed';
    outcomeLabel: string;
    score: number | null;
    stepsCount: number;
    seconds: number;
    summary: string | null;
    friction: string[];
    steps: Step[];
};

export type SiteScore = { key: string; name: string; url: string; score: number; categories: Array<{ key: string; label: string; score: number }> };

export type Report = {
    run: RunSummary;
    audit: { id: number; name: string; url: string };
    summary: string | null;
    screen: { width: number; height: number };
    sites: SiteScore[];
    findings: Finding[];
    journeys: Journey[];
    progress: { journeys: number; pages: number };
};
