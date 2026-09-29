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

export declare class BuildPusherError extends Error {
    status: number;
    body: Record<string, unknown>;
}

export declare class BuildPusher {
    constructor(options: { token: string; baseUrl?: string; fetch?: typeof fetch });
    me(): Promise<{ id: string; name: string; email: string; organization: { id: string; name: string; plan: string } }>;
    projects(): Promise<Project[]>;
    project(projectId: string): Promise<Project>;
    deployments(options?: { limit?: number }): Promise<Deployment[]>;
    deployment(deploymentId: number): Promise<Deployment>;
    log(deploymentId: number): Promise<{ deployment_id: number; status: DeploymentStatus; log: string }>;
    deploy(environmentId: string, options?: { ref?: string }): Promise<Deployment>;
    rollback(deploymentId: number): Promise<Deployment>;
    replaceVariables(environmentId: string, dotenv: string): Promise<{ status: 'applied'; count: number } | { status: 'pending_approval'; change_id: number }>;
    waitForDeployment(deploymentId: number, options?: { timeoutMs?: number; intervalMs?: number }): Promise<Deployment>;
}

export default BuildPusher;
