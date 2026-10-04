// The JSON the Monitoring endpoints return.

import type { Option } from '~/types/ui';

/** A monitor as lists show it. */
export type MonitorSummary = { id: number; name: string; type: string; typeLabel: string; environment: string; target: string; health: string; healthLabel: string; checkedAt: string | null };

/** The monitor form's choices and, when editing, the monitor's settings (never its secrets). */
export type MonitorForm = {
    types: Record<string, string>;
    intervals: Option[];
    queueSettings: Array<{ field: string; label: string; min: number; max: number; default: number | null; required: boolean }>;
    dnsTypes: string[];
    flowExample: string;
    destinations: Array<{ id: number; name: string; type: string; enabled: boolean }>;
    monitor: {
        id: number;
        name: string;
        type: string;
        environmentId: string;
        version: number;
        target: string;
        queueName: string | null;
        queueSettings: Record<string, number | null>;
        heartbeatSchedule: string | null;
        heartbeatGraceMinutes: number | null;
        heartbeatIntervalMinutes: number | null;
        heartbeatCron: string | null;
        heartbeatTimezone: string | null;
        flowSteps: number;
        method: string | null;
        maxDurationMs: number | null;
        statusMin: number | null;
        statusMax: number | null;
        hasBodyContains: boolean;
        hasBearerToken: boolean;
        dnsRecordType: string | null;
        dnsMatch: string | null;
        dnsExpected: number;
        tlsPort: number | null;
        tlsExpiryDays: number | null;
        tcpPort: number | null;
        intervalMinutes: number | null;
        timeoutSeconds: number | null;
        triggerChecks: number | null;
        recoveryChecks: number | null;
        enabled: boolean;
        destinations: number[];
        notifyOpened: boolean;
        notifyRecovered: boolean;
    } | null;
};

/** An alert rule as lists show it. */
export type AlertRuleSummary = { id: number; name: string; condition: string; windowMinutes: number; environment: string; enabled: boolean; state: string; stateLabel: string };

/** The alert rule form's choices and, when editing, the rule's settings. */
export type AlertRuleForm = {
    metrics: Array<Option & { disabled: boolean; needs: 'match' | 'objective' | 'numeric' | 'series' | null }>;
    windows: Option[];
    objectives: Option[];
    series: Option[];
    rule: {
        id: number;
        name: string;
        environmentId: string;
        version: number;
        metric: string;
        threshold: number;
        windowMinutes: number;
        service: string | null;
        minimumSamples: number;
        matchText: string | null;
        objectiveId: number | null;
        seriesId: number | null;
        aggregation: string | null;
        comparison: string | null;
        freshnessSeconds: number | null;
        triggerChecks: number;
        recoveryChecks: number;
        enabled: boolean;
    } | null;
};

/** The choices on an alert destination's form. */
export type AlertDestinationOptions = {
    types: Array<Option & { followsPerson: boolean; isPhone: boolean; usesUrl: boolean }>;
    schedules: Option[];
    members: Option[];
    phones: boolean;
    dailyPhoneLimit: number;
};

/** A service level objective with how it's doing. */
export type ObjectiveSummary = {
    id: number;
    name: string;
    projectId: string;
    project: string;
    environment: string;
    indicator: string;
    target: string;
    scope: string;
    window: string;
    enabled: boolean;
    compliance: number | null;
    budgetRemaining: number | null;
    status: string;
};

/** The objective form's settings when editing. */
export type ObjectiveSettings = {
    id: number;
    name: string;
    environmentId: string;
    indicator: string;
    target: number;
    windowDays: number;
    latencyThresholdMs: number | null;
    statusMin: number | null;
    statusMax: number | null;
    service: string | null;
    route: string | null;
    enabled: boolean;
};

/** The dashboard form's choices and, when editing, the dashboard's settings. */
export type DashboardForm = {
    widgets: Option[];
    ranges: Option[];
    dashboard: { name: string; description: string | null; range: string; widgets: string[] } | null;
};

/** The status page form's monitors and, when editing, the page's settings. */
export type StatusPageForm = {
    monitors: Array<{ id: number; name: string; description: string }>;
    baseUrl: string;
    page: { name: string; slug: string; description: string | null; published: boolean; monthlyReport: boolean; components: Record<string, string | null> } | null;
};

/** A telemetry event as lists show it. */
export type EventRow = {
    id: number;
    occurredAt: string;
    type: string;
    typeLabel: string;
    severity: string;
    tone: 'danger' | 'warning' | 'neutral';
    name: string;
    service: string;
    environment: string | null;
    statusCode: number | null;
    duration: string;
    traceId: string | null;
    issueId: number | null;
    hasError: boolean;
    hasWarning: boolean;
};

/** An issue as lists show it. */
export type IssueRow = {
    id: number;
    title: string;
    location: string | null;
    occurrences: number;
    lastSeenAt: string | null;
    assignee: string | null;
    severity: string;
    severityLabel: string;
    status: string;
    statusLabel: string;
    statusTone: 'success' | 'danger' | 'warning' | 'neutral' | 'info';
};

/** Requests, errors and response times for a release or one side of a deployment. */
export type ReleaseMetrics = { requests: number; errorRate: number | null; averageDuration: number | null; exceptions: number };

/** Telemetry totals for a period. */
export type TelemetryTotals = { eventCount: number; requestCount: number; averageDuration: number | null; requestErrorRate: number | null };

/** Telemetry totals with the change from the period before, the event mix and a trend. */
export type TelemetrySummary = TelemetryTotals & { changes: { events: number | null; duration: number | null; errorRate: number | null }; eventBreakdown: Record<string, number>; trend: Array<TelemetryTotals & { label: string }> };

/** What a dashboard widget shows; only the part for its type is set. */
export type DashboardWidgetData = {
    summary?: TelemetrySummary;
    incidents?: Array<{ id: number; projectId: string; project: string | null; title: string; status: string; statusLabel: string; openedAt: string }>;
    monitors?: Array<MonitorSummary & { projectId: string; project: string }>;
    objectives?: ObjectiveSummary[];
    projects?: Array<{ id: string; name: string; environments: number; lastReceivedAt: string | null }>;
};
