// How the Acme theme draws BuildPusher's services: a coloured tile and an icon from its set (components/acme/Icon).

/** A service's tile colour and icon. */
export type ServiceStyle = { tone: string; icon: string };

const styles: Record<string, ServiceStyle> = {
    projects: { tone: 'bg-zinc-800', icon: 'grid' },
    deploy: { tone: 'bg-violet-500', icon: 'rocket' },
    infrastructure: { tone: 'bg-orange-500', icon: 'cpu' },
    monitoring: { tone: 'bg-emerald-600', icon: 'trending' },
    analytics: { tone: 'bg-sky-600', icon: 'pie' },
    security: { tone: 'bg-rose-600', icon: 'shield' },
    audit: { tone: 'bg-amber-500', icon: 'search' },
    account: { tone: 'bg-slate-600', icon: 'workspace' },
};

/**
 * A service's tile colour and icon, or a neutral one for anything else.
 *
 * @param key The service's key, e.g. "deploy".
 */
export const serviceStyle = (key: string | null | undefined): ServiceStyle => styles[key ?? ''] ?? { tone: 'bg-zinc-500', icon: 'grid' };
