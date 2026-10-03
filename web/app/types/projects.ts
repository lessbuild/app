// The JSON the projects and dashboard endpoints return.

export type ProjectCard = { id: string; name: string; description: string | null; serviceNames: string[]; environmentCount: number };

export type ActivityItem = { kind: string; icon: string; tone: string; outcome: string; title: string; actor: string | null; project: string | null; url: string | null; at: string };

export type Dashboard = {
    account: { id: string; name: string } | null;
    projects: ProjectCard[];
    activity: ActivityItem[];
    activityKind: string | null;
    activityKinds: Record<string, string>;
    canCreate: boolean;
};

export type ServiceOption = { key: string; name: string; tagline: string; icon: string };
