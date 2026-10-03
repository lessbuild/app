import { notFound } from 'next/navigation';
import { JourneyView } from '@/components/audit/JourneyView';
import { Modal } from '@/components/signal/Modal';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import { runReport } from '@/lib/report';

/** A journey replayed in a modal over the report. */
export default async function JourneyModal({ params }: { params: Promise<{ project: string; run: string; journey: string }> }) {
    const { project, run, journey: id } = await params;
    const [{ i18n }, report] = await Promise.all([projectContext(project, 'audit'), runReport(project, run)]);
    const journey = report.journeys.find((item) => String(item.id) === id) ?? notFound();

    return (
        <Modal title={journey.goal} description={t(i18n, 'On :site', { site: journey.siteName })} size="large">
            <JourneyView i18n={i18n} journey={journey} report={report} />
        </Modal>
    );
}
