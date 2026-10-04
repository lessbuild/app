// The JSON the Deploy endpoints return.

import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** Where a deploy is: queued, running, live, failed… */
export type BuildStatus = 'queued' | 'running' | 'deploying' | 'awaiting_approval' | 'succeeded' | 'failed' | 'rejected' | 'canceled';

/** The choices on a repository's form. */
export type RepositoryFormOptions = { providers: Option[]; websites: Option[]; environments: Option[] };

export type RepositoryRow = {
    id: number;
    name: string;
    url: string;
    branch: string;
    website: string | null;
    environment: string | null;
    latestBuild: { id: number; status: BuildStatus; createdAt: string | null } | null;
};

export type RepositoriesPage = { overview: ProjectOverview; repositories: RepositoryRow[]; canCreate: boolean };

export type BuildRow = { id: number; status: BuildStatus; revision: string | null; commitMessage: string | null; trigger: string; requester: string | null; createdAt: string | null };

export type RepositoryDetail = {
    id: number;
    name: string;
    url: string;
    branch: string;
    providerId: number | null;
    host: string | null;
    websiteId: number;
    website: string;
    server: string | null;
    environmentId: string | null;
    environment: string | null;
    timezone: string;
    ready: boolean;
    isPreview: boolean;
    deploymentRoot: string | null;
    buildCommands: string | null;
    postDeploymentCommands: string | null;
    autoDeployIncludePaths: string[];
    autoDeployExcludePaths: string[];
    webhookEnabled: boolean;
    webhookLastReceivedAt: string | null;
    buildCacheEnabled: boolean;
    previewsEnabled: boolean;
    previewDomain: string | null;
    previewTtlHours: number;
    previewInitializationCommand: string | null;
    previewDatabaseSourceWebsiteId: number | null;
    previewDatabaseMode: string;
    previewDatabaseAnonymise: boolean;
};

export type RepositoryPage = {
    overview: ProjectOverview;
    repository: RepositoryDetail;
    builds: BuildRow[];
    deliveries: Array<{ id: number; revision: string | null; status: string; createdAt: string | null }>;
    scheduledDeploys: Array<{ id: number; runAt: string; ref: string | null; creator: string | null }>;
    previewSources: Option[];
    sampleRows: number;
    projectSlug: string;
    options: RepositoryFormOptions;
    canDeploy: boolean;
    canManage: boolean;
};

/** Before-and-after measures of a release, keyed by measure. */
export type ObservationMeasures = Partial<Record<'requests' | 'error_rate' | 'latency_ms' | 'visits' | 'conversion_rate', number | null>>;

export type BuildDetail = {
    id: number;
    status: BuildStatus;
    active: boolean;
    repository: { id: number; name: string };
    website: string;
    revision: string | null;
    shortRevision: string | null;
    revisionUrl: string | null;
    ref: string | null;
    commitMessage: string | null;
    requester: string | null;
    trigger: string;
    startedAt: string | null;
    finishedAt: string | null;
    releaseName: string | null;
    setupStage: number;
    failureMessage: string | null;
    approvalNote: string | null;
    rolledBackFrom: number | null;
    redeployedFrom: number | null;
    promotedFrom: { id: number; environment: string | null; note: string | null } | null;
    promotions: Array<{ id: number; environment: string | null }>;
    releaseNotes: Record<string, string[]>;
    commitCount: number;
    destructiveMigrations: string | null;
    observation: { status: string | null; error: string | null; before: ObservationMeasures; after: ObservationMeasures } | null;
    log: string | null;
};

export type BuildPage = {
    overview: ProjectOverview;
    build: BuildDetail;
    stages: string[];
    telemetry: { id: number; releaseId: number; environmentId: string } | null;
    promotionTargets: Option[];
    canDeploy: boolean;
    canApprove: boolean;
};

/** What the live status endpoint answers while a deploy runs. */
export type BuildLiveStatus = { status: BuildStatus; finished: boolean; stage: number; log: string | null };

export type ComparedBuild = {
    id: number;
    status: BuildStatus;
    revision: string | null;
    shortRevision: string | null;
    commitMessage: string | null;
    requester: string | null;
    trigger: string;
    environment: string | null;
    startedAt: string | null;
    seconds: number | null;
    note: string | null;
    failure: string | null;
};

export type BuildChange = { area: string; name: string; kind: 'added' | 'removed' | 'changed'; from: string | null; to: string | null };

export type ComparisonPage = {
    overview: ProjectOverview;
    build: ComparedBuild & { repository: string; website: string };
    baseline: ComparedBuild | null;
    comparison: { durationDelta: number | null; compareUrl: string | null; snapshotsAvailable: boolean; changes: BuildChange[] } | null;
    candidates: Array<{ id: number; status: BuildStatus; revision: string | null; commitMessage: string | null }>;
};

export type DecisionPage = {
    overview: ProjectOverview;
    build: { id: number; status: BuildStatus; awaitingApproval: boolean; repository: string; branch: string; environment: string | null; requester: string | null; revision: string | null; commitMessage: string | null };
    canApprove: boolean;
    reason: string;
};

export type PreviewSummary = { id: number; repository: string; label: string; pullRequest: number | null; title: string | null; status: string };

export type OpenPreview = PreviewSummary & {
    url: string | null;
    branch: string | null;
    revision: string | null;
    shortRevision: string | null;
    expiresAt: string;
    deploysRepositoryId: number | null;
    websiteError: string | null;
    approvedSecrets: { count: number; approver: string | null } | null;
    /** The source environment's variables this person may approve for the preview, by name. */
    approvable: string[];
    canOperate: boolean;
};

export type ClosedPreview = PreviewSummary & { closedAt: string | null; cleanupStatus: string | null; cleanupError: string | null; canOperate: boolean };

export type PreviewsPage = { overview: ProjectOverview; used: number; limit: number | null; allowed: boolean; repositories: Option[]; open: OpenPreview[]; closed: ClosedPreview[] };

export type PipelineRun = { id: number; status: string; createdAt: string | null; builds: Array<{ id: number; status: BuildStatus | null }>; failure: string | null };

export type PipelinesPage = {
    overview: ProjectOverview;
    pipelines: Array<{ id: number; name: string; steps: Array<{ id: number; name: string | null; environment: string | null }>; runs: PipelineRun[] }>;
    repositories: Option[];
    canManage: boolean;
};

export type EnvironmentRow = {
    id: string;
    name: string;
    blocked: boolean;
    locked: boolean;
    requiresApproval: boolean;
    runtime: string | null;
    runtimeVersion: string | null;
    strategy: string;
    variables: number;
    processes: number;
    resources: number;
};

export type EnvironmentsPage = { overview: ProjectOverview; environments: EnvironmentRow[] };

export type ConfigurationPage = {
    overview: ProjectOverview;
    websites: Array<{ id: number; label: string }>;
    repositories: Array<{ id: number; label: string }>;
    secrets: Array<{ id: number; label: string }>;
    reviews: Array<{ id: string; requester: string; createdAt: string | null; changes: number; applicationId: string | null; applicationStatus: string | null; expired: boolean }>;
    workflow: string | null;
    canManage: boolean;
};

/** One change a configuration document makes, as planned. */
export type ConfigurationChange = { action: string; kind: string; name: string; environment: string | null; fields?: string[]; requires_approval?: boolean };

export type ConfigurationPlan = { version: number; project_id: string; changes: ConfigurationChange[]; fingerprint: string; omitted_objects: string; apply_available: boolean };

export type EnvironmentDetail = {
    id: string;
    name: string;
    protected: boolean;
    requireVariableApproval: boolean;
    locked: boolean;
    lockReason: string | null;
    windowDays: number[] | null;
    windowStart: string | null;
    windowEnd: string | null;
    timezone: string;
    requiresApproval: boolean;
    automaticRollback: boolean;
    migrationSafety: boolean;
    strategy: string;
    rollingPauseSeconds: number;
    observationMinutes: number | null;
    rollbackErrorRatePercent: number | null;
    rollbackLatencyPercent: number | null;
    rollbackConversionDropPercent: number | null;
    runtime: string;
    runtimeVersion: string | null;
    containerPort: number | null;
    buildCommand: string | null;
    startCommand: string | null;
    dockerfilePath: string | null;
    composeService: string | null;
    minimumReplicas: number;
    desiredReplicas: number;
    maximumReplicas: number;
    autoscaleEnabled: boolean;
    autoscaleCpuTarget: number;
    autoscaleQueueJobs: number | null;
    autoscaledAt: string | null;
    buildServerId: number | null;
    artifactBucketId: number | null;
    releaseNotesUrl: string | null;
    maintenanceAt: string | null;
    maintenanceSecret: string | null;
    maintenanceError: string | null;
    hibernatedAt: string | null;
    lastActivityAt: string | null;
    hibernateAfterMinutes: number | null;
    recipesRunOnNewWebsites: boolean;
};

export type EnvironmentPage = {
    overview: ProjectOverview;
    environment: EnvironmentDetail;
    blockReason: string | null;
    placements: Array<{ name: string; server: string | null; provider: string | null; region: string | null }>;
    freezes: Array<{ id: number; startsAt: string; endsAt: string; reason: string | null; now: boolean }>;
    variables: Array<{ id: number; key: string; value: string | null; secret: boolean; scope: string; version: number; rotationDueAt: string | null }>;
    scopes: Record<string, string>;
    pendingChanges: Array<{ id: number; summary: string; requester: string | null; mine: boolean; createdAt: string | null }>;
    secretSyncs: Array<{ id: number; name: string; provider: string; lastSyncedAt: string | null; lastError: string | null; lastResult: { added: number; updated: number; removed: number; skipped: string[] } | null }>;
    secretProviders: Record<string, string>;
    processes: Array<{ id: number; name: string; command: string; type: string; replicas: number; enabled: boolean }>;
    resources: Array<{ id: number; name: string; type: string; managed: boolean; variables: string[] }>;
    resourceTypes: Record<string, string>;
    deploySchedules: Array<{ id: number; name: string; cron: string; timezone: string; nextRunAt: string | null; lastResult: string | null }>;
    scalingSchedules: Array<{ id: number; name: string; replicas: number; cron: string; timezone: string; nextRunAt: string | null }>;
    tasks: Array<{
        id: number;
        name: string;
        cron: string;
        timezone: string;
        website: string;
        timeoutSeconds: number;
        lastStatus: string | null;
        runs: Array<{ id: number; status: string; durationMs: number | null; requester: string | null; createdAt: string | null; active: boolean }>;
    }>;
    taskWebsites: Option[];
    plan: { scheduled: boolean; scaling: boolean; hibernation: boolean };
    hibernationMinutes: number[];
    recipes: Array<{ id: number; name: string; deleted: boolean; behind: boolean }>;
    libraryRecipes: Option[];
    destinations: Array<{ id: number; name: string; type: string; enabled: boolean; onSuccess: boolean; onFailure: boolean; onApproval: boolean }>;
    buildServers: Option[];
    storageBuckets: Option[];
    canManage: boolean;
};
