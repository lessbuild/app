// Helpers for the Acme theme's components (components/acme), copied from the theme.

/** A badge colour in the Acme theme. */
export type AcmeTone = 'gray' | 'green' | 'blue' | 'amber' | 'red' | 'violet';

const tones: Record<string, AcmeTone> = {
  // Opportunity stages
  Identify: 'gray', Qualify: 'amber', Capture: 'blue', Proposal: 'violet', Submitted: 'blue', Award: 'green',
  // Priority
  High: 'red', Medium: 'amber', Low: 'gray',
  // Contracts, invoices, compliance
  Active: 'green', Expiring: 'amber', Closed: 'gray', Paid: 'green', Refunded: 'gray',
  Compliant: 'green', Partial: 'amber', Missing: 'red',
  // Relationships & roles
  Teammate: 'green', Partner: 'blue', Competitor: 'red', Owner: 'blue', Admin: 'violet', Member: 'gray', Guest: 'gray',
  Complete: 'green', Drafting: 'blue', Pending: 'amber',
  // Invoices
  Draft: 'gray', Open: 'blue', Overdue: 'red', Void: 'gray',
  // Helpdesk, store, status page
  New: 'violet', Solved: 'gray', Normal: 'gray', Urgent: 'red', Unfulfilled: 'amber', Fulfilled: 'green', Returned: 'gray', Archived: 'gray',
  Monitoring: 'blue', Resolved: 'green', Identified: 'amber', Investigating: 'red',
  // CRM, projects, people ops, subscriptions, campaigns
  Lead: 'gray', Qualified: 'blue', Negotiation: 'amber', Won: 'green', Lost: 'red',
  'On track': 'green', 'At risk': 'red', Done: 'gray',
  'Full-time': 'green', Contract: 'blue', 'On leave': 'amber', Present: 'green', Late: 'amber', Absent: 'red', Remote: 'blue',
  Trialing: 'violet', 'Past due': 'red', Canceled: 'gray',
  Sent: 'green', Sending: 'amber', Scheduled: 'blue',
  // Stratus app
  Healthy: 'green', Degraded: 'red', 'Setting up': 'amber', Verified: 'green', 'Not verified': 'amber', On: 'green', Off: 'gray', Live: 'green', Failed: 'red', Running: 'blue', Queued: 'gray', Building: 'blue', Passed: 'green', Skipped: 'gray', Cancelled: 'gray', Provisioning: 'blue', Ready: 'green', Paused: 'gray', Up: 'green', Down: 'red', Acknowledged: 'amber', Muted: 'gray', Ignored: 'gray', Critical: 'red', Info: 'gray', Blocked: 'red', Allowed: 'green',
}

/**
 * The badge colour for a status-like word, as the Acme theme maps them (English words; pass a tone yourself for
 * translated ones).
 *
 * @param status The status, e.g. "Paid".
 */
export const statusTone = (status: string): AcmeTone => tones[status] ?? 'gray';

/**
 * Up to two initials for a name, for avatars and monograms.
 *
 * @param name The name.
 */
export function initials(name: string): string {
    return name.replace(/^(Col|Dr|Mr|Ms)\.\s*/, '').split(/\s+/).filter((part) => /^[\p{L}\p{N}]/u.test(part)).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
}

/**
 * A smooth SVG path through points (Catmull-Rom as cubic Béziers, with low tension so it doesn't overshoot), for the
 * theme's line charts and sparklines.
 *
 * @param points The points, as [x, y].
 * @param tension How far the curve bends towards its neighbours.
 */
export function smoothPath(points: readonly (readonly [number, number])[], tension = 0.18): string {
    if (points.length < 2) {
        return '';
    }
    let d = `M${points[0]![0]},${points[0]![1]}`;
    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i]!;
        const p1 = points[i]!;
        const p2 = points[i + 1]!;
        const p3 = points[i + 2] ?? p2;
        const c1 = [p1[0] + (p2[0] - p0[0]) * tension, p1[1] + (p2[1] - p0[1]) * tension];
        const c2 = [p2[0] - (p3[0] - p1[0]) * tension, p2[1] - (p3[1] - p1[1]) * tension];
        d += ` C${c1[0]},${c1[1]} ${c2[0]},${c2[1]} ${p2[0]},${p2[1]}`;
    }
    return d;
}

/**
 * The theme's badge colour for one of BuildPusher's tones (success, warning…), so the API's tones show in its badges.
 *
 * @param tone The tone, e.g. "success".
 */
export const acmeTone = (tone: string | null | undefined): AcmeTone => ({ success: 'green', danger: 'red', warning: 'amber', info: 'blue', accent: 'violet', neutral: 'gray' } as Record<string, AcmeTone>)[tone ?? ''] ?? (['gray', 'green', 'blue', 'amber', 'red', 'violet'].includes(tone ?? '') ? tone as AcmeTone : 'gray');
