export declare const FINISHED: readonly ['succeeded', 'failed', 'canceled', 'rejected'];

export type DeploymentStatus = 'queued' | 'awaiting_approval' | 'rejected' | 'deploying' | 'running' | 'succeeded' | 'failed' | 'canceled';

export interface Deployment {
    id: number;
    repository_id: number;
    environment_id: string | null;
    status: DeploymentStatus;
    trigger: string;
    revision: string | null;
    ref: string | null;
    promoted_from_build_id: number | null;
    created_at: string | null;
    finished_at: string | null;
}

export interface Environment {
    id: string;
    name: string;
    slug: string;
    type: string;
    desired_replicas: number;
    state: 'running' | 'hibernated';
}

export interface Project {
    id: string;
    name: string;
    slug: string;
    environments: Environment[];
}

export interface AnalyticsSite {
    id: number;
    name: string;
    project_id: string;
    public_id: string;
    domains: string[];
    timezone: string;
    verified: boolean;
    last_event_at: string | null;
}

export interface RankedItem {
    label: string;
    value: number;
}

export interface AnalyticsReport {
    site_id: number;
    period: { days: number; start: string; end: string; timezone: string };
    filters: Record<string, string>;
    metrics: Record<'pageviews' | 'visitors' | 'visits' | 'conversion_rate' | 'bounce_rate' | 'visit_duration', { value: number | null; change: string | null }>;
    series: { granularity: 'hour' | 'day'; points: { date: string; value: number }[] };
    pages: RankedItem[];
    entry_pages: RankedItem[];
    exit_pages: RankedItem[];
    sources: RankedItem[];
    countries: RankedItem[];
    campaigns: RankedItem[];
    devices: RankedItem[];
    browsers: RankedItem[];
    operating_systems: RankedItem[];
    outbound_links: RankedItem[];
    file_downloads: RankedItem[];
    not_found: RankedItem[];
    goals: { name: string; value: number; kind: string; revenue: string | null }[];
    page_speed: { samples: number; metrics: Record<'lcp' | 'inp' | 'cls' | 'ttfb', { value: number | null; rating: 'good' | 'needs_improvement' | 'poor' | null }>; slowPages: { path: string; lcp: number; samples: number }[] } | null;
}

export declare class BuildPusherError extends Error {
    status: number;
    body: Record<string, unknown>;
}

export declare class BuildPusher {
    constructor(options: { token: string; baseUrl?: string; fetch?: typeof fetch });
    me(): Promise<{ id: string; name: string; email: string; organization: { id: string; name: string; plan: string } }>;
    projects(): Promise<Project[]>;
    project(projectId: string): Promise<Project>;
    analyticsSites(): Promise<AnalyticsSite[]>;
    analyticsReport(siteId: number, options?: { days?: 1 | 7 | 30 | 90 | 365; path?: string; source?: string; campaign?: string; device?: string; country?: string }): Promise<AnalyticsReport>;
    analyticsRows(siteId: number, options: { from: string; to: string; dimension?: 'all' | 'path' | 'source' | 'channel' | 'campaign' | 'country' | 'city' | 'device' | 'browser' | 'operating_system' | 'screen_size' }): Promise<Array<{ date: string; value: string | null; pageviews: number; visits: number; visitors: number; conversions: number; converted_visits: number; bounces: number; bounce_eligible: number }>>;
    analyticsEvents(siteId: number, events: Array<{ type: 'pageview' | 'event'; path: string; name?: string; properties?: Record<string, unknown>; ip?: string; user_agent?: string; referrer?: string; utm_source?: string; utm_medium?: string; utm_campaign?: string; utm_term?: string; utm_content?: string }>): Promise<{ batch_id: string | null; accepted: number; skipped: number }>;
    deployments(options?: { limit?: number }): Promise<Deployment[]>;
    deployment(deploymentId: number): Promise<Deployment>;
    log(deploymentId: number): Promise<{ deployment_id: number; status: DeploymentStatus; log: string }>;
    deploy(environmentId: string, options?: { ref?: string }): Promise<Deployment>;
    rollback(deploymentId: number): Promise<Deployment>;
    replaceVariables(environmentId: string, dotenv: string): Promise<{ status: 'applied'; count: number } | { status: 'pending_approval'; change_id: number }>;
    waitForDeployment(deploymentId: number, options?: { timeoutMs?: number; intervalMs?: number }): Promise<Deployment>;
}

export default BuildPusher;
