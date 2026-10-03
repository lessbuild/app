import type { Metadata } from 'next';
import Link from 'next/link';
import { ActivityFeed } from '@/components/projects/ActivityFeed';
import { SampleProjectButton } from '@/components/projects/ProjectActions';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/signal/Badge';
import { ButtonLink } from '@/components/signal/Button';
import { EmptyState } from '@/components/signal/EmptyState';
import { Icon } from '@/components/signal/Icon';
import { PageHeader } from '@/components/signal/PageHeader';
import { pageContext } from '@/lib/context';
import { t, tc } from '@/lib/i18n';
import type { Dashboard } from '@/lib/projects';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'Projects' };

/** The account's projects and the team's recent activity: where people land after signing in. */
export default async function DashboardPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const query = await searchParams;
    const [{ shell, i18n }, data] = await Promise.all([pageContext(), api<Dashboard>('/dashboard', { activity: query.activity })]);
    const verified = query.verified === '1';

    return (
        <AppShell shell={shell} i18n={i18n}>
            <PageHeader
                i18n={i18n}
                eyebrow={data.account?.name}
                title={t(i18n, 'Projects')}
                description={t(i18n, 'Each project groups the environments, domains and services of one app or site.')}
                actions={data.canCreate && data.projects.length > 0 ? (
                    <>
                        <ButtonLink href="/projects/templates">{t(i18n, 'From a template')}</ButtonLink>
                        <ButtonLink href="/projects/create" variant="primary" scroll={false}><Icon name="plus" className="h-4 w-4" />{t(i18n, 'New project')}</ButtonLink>
                    </>
                ) : undefined}
            />
            {verified && <div className="ui-alert ui-alert--success ui-alert-success" role="status">{t(i18n, 'Your email address is verified. Welcome!')}</div>}

            {data.account === null ? (
                <EmptyState title={t(i18n, 'You’re not in an account')} description={t(i18n, 'Ask someone to invite you, or create an account.')} />
            ) : data.projects.length === 0 ? (
                <EmptyState
                    icon="layers"
                    title={t(i18n, 'Create your first project')}
                    description={data.canCreate
                        ? t(i18n, 'A project is one app or site. You’ll add domains and turn on Deploy, Monitoring, Analytics or Infrastructure next.')
                        : t(i18n, 'No projects yet. Someone who manages projects in :account can create one.', { account: data.account.name })}
                    action={data.canCreate ? (
                        <>
                            <ButtonLink href="/projects/create" variant="primary" scroll={false}>{t(i18n, 'Create a project')}</ButtonLink>
                            <ButtonLink href="/projects/templates">{t(i18n, 'Start from a template')}</ButtonLink>
                            <SampleProjectButton />
                        </>
                    ) : undefined}
                />
            ) : (
                <div className="grid items-start gap-6 xl:grid-cols-3">
                    <ul className="grid gap-4 sm:grid-cols-2 xl:col-span-2" aria-label={t(i18n, 'Projects')}>
                        {data.projects.map((project) => (
                            <li key={project.id}>
                                <Link href={`/projects/${project.id}`} className="ui-card ui-card--interactive block h-full p-5">
                                    <p className="text-base font-extrabold text-ink">{project.name}</p>
                                    {project.description && <p className="mt-1 line-clamp-2 text-sm text-muted">{project.description}</p>}
                                    <div className="mt-4 flex flex-wrap items-center gap-1.5">
                                        {project.serviceNames.length > 0
                                            ? project.serviceNames.map((name) => <Badge key={name} tone="accent">{name}</Badge>)
                                            : <Badge>{t(i18n, 'No services yet')}</Badge>}
                                        <span className="text-xs text-muted">· {tc(i18n, ':count environment|:count environments', project.environmentCount)}</span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <ActivityFeed initial={data.activity} kind={data.activityKind} kinds={data.activityKinds} />
                </div>
            )}
        </AppShell>
    );
}
