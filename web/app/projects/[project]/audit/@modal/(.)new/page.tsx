import { AuditWizard } from '@/components/audit/AuditWizard';
import { Modal } from '@/components/signal/Modal';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { AuditPlan, Goal } from '@/lib/types';

/** The new-audit wizard in a modal over the page it was opened from. */
export default async function NewAuditModal({ params }: { params: Promise<{ project: string }> }) {
    const { project } = await params;
    const [{ i18n }, data] = await Promise.all([projectContext(project, 'audit'), api<{ plan: AuditPlan; goals: Goal[] }>(`/projects/${project}/audit`)]);

    return (
        <Modal title={t(i18n, 'New audit')} description={t(i18n, 'Four short steps. The first report is ready in a few minutes.')} size="wide">
            <AuditWizard projectId={project} plan={data.plan} goals={data.goals} />
        </Modal>
    );
}
