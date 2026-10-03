import type { Metadata } from 'next';
import { AuditWizard } from '@/components/audit/AuditWizard';
import { PageHeader } from '@/components/signal/PageHeader';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { AuditPlan, Goal } from '@/lib/types';

export const metadata: Metadata = { title: 'New audit' };

/** The new-audit wizard as a full page, for a refresh or a shared link; from the app it opens as a modal. */
export default async function NewAuditPage({ params }: { params: Promise<{ project: string }> }) {
    const { project } = await params;
    const [{ i18n }, data] = await Promise.all([projectContext(project, 'audit'), api<{ plan: AuditPlan; goals: Goal[] }>(`/projects/${project}/audit`)]);

    return (
        <>
            <PageHeader i18n={i18n} title={t(i18n, 'New audit')} breadcrumbs={[{ label: t(i18n, 'Audits'), href: `/projects/${project}/audit` }]} />
            <div className="ui-card max-w-3xl p-5 sm:p-6">
                <AuditWizard projectId={project} plan={data.plan} goals={data.goals} />
            </div>
        </>
    );
}
