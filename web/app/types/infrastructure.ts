// The JSON the Infrastructure endpoints return.

import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** A server as lists show it. */
export type ServerSummary = { id: number; name: string; type: string; typeLabel: string; ip: string | null; provider: string | null; region: string | null; status: string };

export type ServersPage = { overview: ProjectOverview; accountName: string; servers: ServerSummary[]; limit: number | null; types: Option[]; canManage: boolean };

/** A cloud provider's regions, sizes and images for the new server form. */
export type ServerCatalog = { regions: Array<{ id: string; label: string }>; sizes: Array<{ id: string; label: string }>; images: Array<{ id: string; label: string }> };

export type ServerCreateForm = {
    overview: ProjectOverview;
    providers: Option[];
    providerId: string | null;
    catalog: ServerCatalog | null;
    catalogError: string | null;
    types: Option[];
    recipes: Array<{ id: number; name: string; description: string | null }>;
};

/** Where a cron job, process, firewall rule or service stands on the server. */
export type ServerTaskState = { status: string; error: string | null };

export type ReplicaRow = { id: number; name: string; address: string | null; status: string | null; lagSeconds: number | null; error: string | null; checkedAt: string | null };

export type ServerPage = {
    overview: ProjectOverview;
    server: {
        id: number;
        name: string;
        label: string;
        displayName: string | null;
        type: string;
        typeLabel: string;
        ip: string | null;
        privateIp: string | null;
        provider: string | null;
        region: string | null;
        status: string;
        provisioning: boolean;
        stage: number;
        finalStage: number;
        step: string | null;
        error: string | null;
        failurePhase: string | null;
        provisionedAt: string | null;
        sshPort: number;
        hostFingerprint: string | null;
        hasHostKey: boolean;
        size: string | null;
        image: string | null;
        databaseEngine: string | null;
        nodeVersion: string | null;
        installsNode: boolean;
        snapshotBeforeChanges: boolean;
        trustPrivateNetwork: boolean;
    };
    latest: { cpu: number; memory: number; disk: number; load: number; recordedAt: string; uptimeDays: number; processes: number } | null;
    cpu: Array<{ at: string; value: number }>;
    alertRules: Array<{ id: number; name: string; metric: string; operator: string; threshold: number; readings: number; everyServer: boolean; alerting: boolean }>;
    alertMetrics: Record<string, string>;
    diagnostics: { running: boolean; finishedAt: string | null; checks: Array<{ name: string; detail: string; passed: boolean }> } | null;
    diskScan: { status: string; error: string | null; scannedAt: string | null; free: number | null; size: number | null; categories: Array<{ key: string; label: string; bytes: number }> } | null;
    recovery: {
        plan: { destinationId: number; destination: string; retentionDays: number; earliest: string; setupStatus: string | null } | null;
        destinations: Option[];
    } | null;
    replication: { primary: { name: string; self: ReplicaRow } | null; replicas: ReplicaRow[]; candidates: Option[] } | null;
    cronJobs: Array<ServerTaskState & { id: number; command: string; schedule: string; frequency: string; user: string }>;
    cronPresets: Record<string, string>;
    processes: Array<ServerTaskState & { id: number; name: string; command: string; copies: number; user: string; directory: string | null; stopWaitSeconds: number }>;
    processPresets: Record<string, { name: string; command: string; processes: number; stop_wait_seconds: number }>;
    firewallRules: Array<ServerTaskState & { id: number; name: string; port: string; protocol: string; source: string | null; from: string }>;
    services: Array<{
        kind: string;
        name: string;
        description: string;
        secretLabel: string;
        installed: (ServerTaskState & { id: number; listen: string; secret: string | null; address: string; privateAddress: string | null }) | null;
    }>;
    log: { type: string; types: string[]; text: string | null; error: string | null; fetching: boolean; refreshedAt: string | null };
    logShipping: { status: string; error: string | null; environmentId: string; projectId: string; project: string; environment: string } | null;
    logEnvironments: Option[];
    snapshots: Array<{ id: number; status: string; reason: string; error: string | null; createdAt: string | null }>;
    nodeVersions: string[];
    canManage: boolean;
    canRunCommands: boolean;
    canOpenTerminal: boolean;
    canDelete: boolean;
};

/** What the server status endpoint answers while it's being set up. */
export type ServerLiveStatus = {
    status: string;
    stage: number;
    final_stage: number;
    step: string | null;
    reason: string | null;
    public_ip: string | null;
    ssh: string;
    host_key: string | null;
    finished: boolean;
};

export type CommandsPage = {
    overview: ProjectOverview;
    server: { id: number; label: string; active: boolean };
    executions: Array<{ id: number; command: string; status: string; exitCode: number | null; finished: boolean; user: string | null; createdAt: string | null }>;
    nextCursor: string | null;
    previousCursor: string | null;
    status: string | null;
    statuses: string[];
    selected: { id: number; command: string; status: string; exitCode: number | null; output: string | null } | null;
    timeoutSeconds: number;
    retentionDays: number;
    canManage: boolean;
};

export type TerminalPage = {
    overview: ProjectOverview;
    server: { id: number; label: string };
    terminal: { id: string; active: boolean; status: string; closeReason: string | null; columns: number; rows: number };
    ownBrowser: boolean;
    idleMinutes: number;
    sessionMinutes: number;
};

/** The choices on a website's form. */
export type WebsiteFormOptions = { hosts: Option[]; environments: Option[]; healthIntervals: number[]; failureThresholds: number[] };

export type WebsitesPage = {
    overview: ProjectOverview;
    websites: Array<{ id: number; name: string; url: string; server: string | null; status: string }>;
    limit: number | null;
    options: WebsiteFormOptions | null;
    canManage: boolean;
};

/** A website backup as lists show it. */
export type BackupSummary = {
    id: number;
    websiteId: number;
    website: string;
    status: string;
    destination: string;
    sizeBytes: number | null;
    scheduled: boolean;
    secondaryStatus: string | null;
    error: string | null;
    restore: { status: string; error: string | null } | null;
    verification: { status: string; error: string | null } | null;
    restorable: boolean;
    createdAt: string | null;
};

export type WebsiteDetail = {
    id: number;
    name: string;
    url: string;
    description: string | null;
    serverId: number | null;
    server: string | null;
    status: string;
    provisioning: boolean;
    stage: number;
    finalStage: number;
    error: string | null;
    cleanupError: string | null;
    directory: string;
    database: string;
    releaseRetention: number;
    environmentId: string | null;
    healthCheckEnabled: boolean;
    healthCheckPath: string;
    healthCheckIntervalMinutes: number;
    healthFailureThreshold: number;
    selfHealing: boolean;
    envFile: string | null;
    phpVersion: string;
    reverb: boolean;
    caddyDirectives: string | null;
    caddyError: string | null;
};

export type WebsitePage = {
    overview: ProjectOverview;
    website: WebsiteDetail;
    log: string | null;
    health: { monitor: { id: number; projectId: string; label: string; state: string } | null; environment: { project: string } | null };
    domains: Array<{
        id: number;
        hostname: string;
        type: string;
        redirectUrl: string | null;
        temporary: boolean;
        dnsStatus: string;
        sslStatus: string;
        certificateExpiresAt: string | null;
        dnsProvider: string | null;
        canSync: boolean;
        edge: { proxied: boolean; blockedCountries: string; blockedIps: string; rateLimit: number | null } | null;
        edgeError: string | null;
    }>;
    dnsProviders: Option[];
    temporaryDomains: boolean;
    inspection: {
        status: string;
        error: string | null;
        sizeBytes: number | null;
        tables: string[];
        connections: number | null;
        collectedAt: string | null;
        tuning: string[];
        slowLogEnabled: boolean;
        slowQueries: Array<{ query: string; count: number; average: number; slowest: number; rows: number }>;
    } | null;
    databaseUsers: Array<{ id: number; username: string; privilege: string; expiresAt: string | null; status: string; error: string | null }>;
    privileges: Record<string, string>;
    copyTargets: Array<Option & { production: boolean }>;
    copies: Array<{ id: number; source: string; target: string; status: string; error: string | null; createdAt: string | null }>;
    backups: BackupSummary[];
    schedules: Array<{ id: number; frequency: string; weekday: number; time: string; destination: string; secondaryDestination: string | null; retention: number; monthlyDrill: boolean }>;
    backupDestinations: Option[];
    phpVersions: string[];
    defaultPhpVersion: string;
    caddyConfiguration: string | null;
    options: WebsiteFormOptions | null;
    canManage: boolean;
    canBackUp: boolean;
    canManageDatabase: boolean;
    canBrowseFiles: boolean;
};

export type WebsiteFiles = {
    path: string;
    file: string | null;
    phrase: string | null;
    folder: { entries: Array<{ name: string; type: string; size: number; modified: number }>; error: string | null } | null;
    tail: { content: string; error: string | null } | null;
    search: { matches: Array<{ file: string; line: number; text: string }>; error: string | null } | null;
};

/** S3-compatible storage services, with how to fill in their region and endpoint. */
export type StoragePresets = Record<string, { name: string; description: string; endpoint: string; region: string }>;
