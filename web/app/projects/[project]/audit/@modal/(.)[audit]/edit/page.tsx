import { AuditWizard } from '@/components/audit/AuditWizard';
import { Modal } from '@/components/signal/Modal';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { AuditDetail, Goal } from '@/lib/types';

/** Change an audit in a modal over its page. */
export default async function EditAuditModal({ params }: { params: Promise<{ project: string; audit: string }> }) {
    const { project, audit: id } = await params;
    const [{ i18n }, data] = await Promise.all([projectContext(project, 'audit'), api<{ audit: AuditDetail; goals: Goal[] }>(`/projects/${project}/audit/${id}`)]);

    return (
        <Modal title={t(i18n, 'Edit :name', { name: data.audit.name })} size="wide">
            <AuditWizard projectId={project} plan={data.audit.plan} goals={data.goals} audit={data.audit} />
        </Modal>
    );
}
