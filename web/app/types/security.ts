// The JSON the Security endpoints return.

import type { Tone } from '~/types/ui';

/** A finding as the Security pages list it, with the fix the page can apply, if any. */
export type FindingRow = {
    id: number;
    severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
    severityLabel: string;
    tone: Tone;
    source: string;
    sourceLabel: string;
    subject: string | null;
    title: string;
    detail: string | null;
    fix: string | null;
    url: string | null;
    status: 'open' | 'ignored' | 'resolved';
    ignoredReason: string | null;
    lastSeenAt: string;
    resolvedAt: string | null;
    fixAction: string | null;
    fixLabel: string | null;
    fixWarning: string | null;
    fixing: boolean;
    fixError: string | null;
};

/** One of Security's checks and how its last scan went. */
export type SecurityCheck = { kind: string; label: string; included: boolean; status: 'queued' | 'running' | 'done' | 'failed' | null; finishedAt: string | null; error: string | null; findings: number };

/** A member as the access review shows them. */
export type AccessMember = { id: string; name: string; email: string; role: string; twoFactor: boolean; projects: number | null; isYou: boolean };

/** An API token as the access review shows it. */
export type AccessToken = { id: number; name: string; abilities: string[]; owner: string | null; lastUsedAt: string | null; expiresAt: string | null };

/** Someone's personal SSH access to one of the project's servers. */
export type AccessGrant = { id: number; name: string; server: string };
