// The JSON the Analytics endpoints return.

import type { ProjectOverview } from '~/types/projects';

/** A site as lists and pickers show it. */
export type SiteRow = { id: number; name: string; domains: string[]; verified: boolean; collecting: boolean; lastEventAt: string | null };

/** The report's period, and the query that asks for it again. */
export type ReportPeriod = { days: number; custom: boolean; compare: 'previous' | 'year' | 'none'; start: string; end: string; query: Record<string, string | number> };

/** The "right now" panel. */
export type LiveVisitors = { visitorCount: number; events: Array<{ type: string; path: string | null; occurredAt: string; source: string }> };

/** One of the report's ranked lists; `filter` is the report filter a row narrows to. */
export type ReportList = { key: string; title: string; empty: string | null; filter: string | null; items: Array<{ value: string; label: string; count: number }> };

/** A site's report for a period, as ReportPayload builds it. */
export type AnalyticsReport = {
    period: ReportPeriod;
    timezone: string;
    granularity: 'day' | 'hour';
    metrics: Array<{ label: string; value: string; change: string | null }>;
    series: Array<{ date: string; value: number }>;
    previousSeries: Array<{ date: string; value: number }> | null;
    lists: ReportList[];
    engagement: Array<{ path: string; pageviews: number; seconds: number | null; scroll: number | null }>;
    vitals: { samples: number; metrics: Record<string, { value: number | null; rating: 'good' | 'needs_improvement' | 'poor' | null }>; slowPages: Array<{ path: string; lcp: number; samples: number }> };
    searchTerms: { property: string; error: string | null; rows: Array<{ query: string; clicks: number; impressions: number; ctr: number; position: number }> } | null;
    goals: Array<{ name: string; value: number; kind: string; revenue: string | null }>;
    recent: LiveVisitors;
    lastProcessedAt: string | null;
    hasData: boolean;
};

/** The report filters (null when unset). */
export type ReportFilters = Record<string, string | null>;

/** The Analytics overview page. */
export type OverviewPage = {
    overview: ProjectOverview;
    sites: SiteRow[];
    site: SiteRow | null;
    filters: ReportFilters;
    report: AnalyticsReport | null;
    annotations: Array<{ id: number; date: string; text: string }>;
    releases: Array<{ id: number; version: string; environment: string; deployedAt: string; fromDeploy: boolean }>;
    today: string | null;
    retentionDays: number;
    canManage: boolean;
};

/** A shared or view-only report. */
export type SharedReportPage = { site: string; locked: boolean; embed: boolean; filters?: ReportFilters; report?: AnalyticsReport; today?: string };

/** Pages that show one site at a time, with the sites to choose from. */
export type SitePage = { overview: ProjectOverview; sites: SiteRow[]; site: SiteRow | null; canManage: boolean };

/** One step of a funnel. */
export type FunnelStep = { kind: string; match: string; value: string };

/** A site's settings, as its settings form shows them. */
export type SiteSettings = { name: string; domains: string[]; timezone: string; excludedPaths: string[]; excludedIps: string[]; customProperties: string[]; contentGroups: Array<{ name: string; pattern: string }>; blockedReferrers: string[] };
