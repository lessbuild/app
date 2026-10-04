// The JSON the Audit endpoints return.

export type RunStatus = 'queued' | 'running' | 'done' | 'failed';

/** A run as lists show it. */
export type RunSummary = { id: number; status: RunStatus; trigger: 'manual' | 'scheduled'; score: number | null; createdAt: string; finishedAt: string | null; error: string | null };

/** What the plan allows, and whether the person may manage audits. */
export type AuditPlan = { tier: string | null; runsUsed: number; runsAllowance: number | null; competitorLimit: number | null; auditLimit: number | null; schedules: string[]; canManage: boolean };

/** A task the visitor can try. */
export type Goal = { value: string; label: string };

/** An audit as the list shows it. */
export type AuditListItem = { id: number; name: string; url: string; schedule: string; competitors: number; latestRun: RunSummary | null };

/** A competitor, entered or suggested. */
export type Competitor = { id?: number; name: string; url: string; source?: string; reason: string | null };

/** One audit with its tasks, competitors, schedule and runs. */
export type AuditDetail = {
    id: number; name: string; url: string; journeys: Array<{ key: string; label: string; goal: string }>; schedule: string; nextRunAt: string | null;
    competitors: Competitor[]; runs: RunSummary[]; plan: AuditPlan;
};

/** An element outlined on a screenshot, in screen pixels. */
export type Box = { x: number; y: number; width: number; height: number };

/** Something to improve, with its evidence. */
export type Finding = {
    id: number; category: string; categoryLabel: string; severity: 'high' | 'medium' | 'low'; effort: 'small' | 'medium' | 'large'; title: string; detail: string;
    recommendation: string; pageUrl: string | null; screenshotUrl: string | null; boxes: Box[]; mockupUrl: string | null; competitorNote: string | null;
};

/** One step of a journey: what the visitor did and why. */
export type Step = { position: number; url: string; action: { type: string; label?: string; text?: string; direction?: string }; thought: string | null; screenshotUrl: string | null; boxes: Box[] };

/** A task tried on one site. */
export type Journey = {
    id: number; siteKey: string; siteName: string; siteUrl: string; goal: string; outcome: 'succeeded' | 'struggled' | 'failed'; outcomeLabel: string; score: number | null;
    stepsCount: number; seconds: number; summary: string | null; friction: string[]; steps: Step[];
};

/** A site's scores, overall and by category. */
export type SiteScore = { key: string; name: string; url: string; score: number; categories: Array<{ key: string; label: string; score: number }> };

/** A run's report. */
export type AuditReport = {
    run: RunSummary; audit: { id: number; name: string; url: string }; summary: string | null; screen: { width: number; height: number };
    sites: SiteScore[]; findings: Finding[]; journeys: Journey[]; progress: { journeys: number; pages: number };
};
