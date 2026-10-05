// The JSON the projects and dashboard endpoints return.

export type ProjectCard = {
    id: string; name: string; description: string | null; serviceNames: string[]; environmentCount: number;
    serviceKeys: string[]; health: 'healthy' | 'degraded' | 'setting_up'; healthLabel: string; openIncidents: number; lastDeployAt: string | null; visitors: number[];
};

/** Something on the dashboard that needs someone now: an open incident or a failed deploy. */
export type AttentionItem = { kind: 'incident' | 'deploy'; icon: string; tone: string; title: string; text: string; url: string; action: string };

export type ActivityItem = { kind: string; icon: string; tone: string; outcome: string; title: string; actor: string | null; project: string | null; url: string | null; at: string };

export type Dashboard = {
    account: { id: string; name: string } | null;
    projects: ProjectCard[];
    attention: AttentionItem[];
    activity: ActivityItem[];
    activityKind: string | null;
    activityKinds: Record<string, string>;
    canCreate: boolean;
};

export type ServiceOption = { key: string; name: string; tagline: string; icon: string };

export type ProjectSummary = { id: string; name: string; description: string | null; accountName: string; isSample: boolean; checklistDismissed: boolean; createdAt: string };

export type EnvironmentSummary = { id: string; name: string; kind: string; kindLabel: string };

export type ServiceCard = ServiceOption & { enabled: boolean; canUse: boolean; canManage: boolean; url: string; sections: Array<{ label: string; url: string }> };

/** A project's header, environments and services, shared by its pages. */
export type ProjectOverview = { project: ProjectSummary; environments: EnvironmentSummary[]; services: ServiceCard[]; canManage: boolean };

export type SetupStep = {
    key: string;
    title: string;
    description: string;
    state: 'done' | 'working' | 'todo';
    detail: string | null;
    actionLabel: string | null;
    actionUrl: string | null;
    icon: string;
    /** The dialog the button opens over the guide instead of going to actionUrl. */
    dialog: 'add-provider' | 'create-website' | 'add-variable' | 'add-site' | null;
    /** What that dialog is for, when it needs it (the environment of add-variable). */
    dialogFor: string | null;
};

export type ProjectSetup = { steps: SetupStep[] };

/** One entry of a project's recent changes, from the audit log. */
export type AuditEntryView = { id: string; actor: string; actorEmail?: string | null; description: string; ipAddress?: string | null; device?: string | null; at: string; category?: string };

export type DomainRow = { id: string; name: string; environment: string | null; verifiedAt: string | null; lastCheckedAt: string | null; recordName: string; recordValue: string };

export type ProjectTemplate = { key: string; name: string; description: string; savedId: number | null; environments: number; monitors: number; goals: number; services: string[]; icon: string };
