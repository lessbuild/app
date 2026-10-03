import { notFound } from 'next/navigation';
import { FindingView } from '@/components/audit/FindingView';
import { Modal } from '@/components/signal/Modal';
import { projectContext } from '@/lib/context';
import { runReport } from '@/lib/report';

/** One finding in a modal over the report. */
export default async function FindingModal({ params }: { params: Promise<{ project: string; run: string; finding: string }> }) {
    const { project, run, finding: id } = await params;
    const [{ i18n }, report] = await Promise.all([projectContext(project, 'audit'), runReport(project, run)]);
    const finding = report.findings.find((item) => String(item.id) === id) ?? notFound();

    return (
        <Modal title={finding.title} size="large">
            <FindingView i18n={i18n} finding={finding} report={report} />
        </Modal>
    );
}
