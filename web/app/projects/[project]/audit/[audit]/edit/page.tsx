import type { Metadata } from 'next';
import { AuditWizard } from '@/components/audit/AuditWizard';
import { PageHeader } from '@/components/signal/PageHeader';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { AuditDetail, Goal } from '@/lib/types';

export const metadata: Metadata = { title: 'Edit audit' };

/** Change an audit, as a full page. */
export default async function EditAuditPage({ params }: { params: Promise<{ project: string; audit: string }> }) {
    const { project, audit: id } = await params;
    const [{ i18n }, data] = await Promise.all([projectContext(project, 'audit'), api<{ audit: AuditDetail; goals: Goal[] }>(`/projects/${project}/audit/${id}`)]);

    return (
        <>
            <PageHeader i18n={i18n} title={t(i18n, 'Edit audit')} breadcrumbs={[{ label: t(i18n, 'Audits'), href: `/projects/${project}/audit` }, { label: data.audit.name, href: `/projects/${project}/audit/${id}` }]} />
            <div className="ui-card max-w-3xl p-5 sm:p-6">
                <AuditWizard projectId={project} plan={data.audit.plan} goals={data.goals} audit={data.audit} />
            </div>
        </>
    );
}
