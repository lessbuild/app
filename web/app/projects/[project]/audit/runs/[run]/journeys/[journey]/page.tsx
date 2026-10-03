import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { JourneyView } from '@/components/audit/JourneyView';
import { PageHeader } from '@/components/signal/PageHeader';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import { runReport } from '@/lib/report';

export const metadata: Metadata = { title: 'Journey' };

/** A journey replayed as a full page; from the report it opens as a modal. */
export default async function JourneyPage({ params }: { params: Promise<{ project: string; run: string; journey: string }> }) {
    const { project, run, journey: id } = await params;
    const [{ i18n }, report] = await Promise.all([projectContext(project, 'audit'), runReport(project, run)]);
    const journey = report.journeys.find((item) => String(item.id) === id) ?? notFound();
    const base = `/projects/${project}/audit`;

    return (
        <>
            <PageHeader i18n={i18n} title={journey.goal} breadcrumbs={[{ label: t(i18n, 'Audits'), href: base }, { label: report.audit.name, href: `${base}/runs/${run}` }]} />
            <JourneyView i18n={i18n} journey={journey} report={report} />
        </>
    );
}
