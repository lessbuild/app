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
