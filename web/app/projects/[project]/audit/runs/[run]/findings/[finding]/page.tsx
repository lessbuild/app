import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { FindingView } from '@/components/audit/FindingView';
import { PageHeader } from '@/components/signal/PageHeader';
import { projectContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import { runReport } from '@/lib/report';

export const metadata: Metadata = { title: 'Finding' };

/** One finding as a full page; from the report it opens as a modal. */
export default async function FindingPage({ params }: { params: Promise<{ project: string; run: string; finding: string }> }) {
    const { project, run, finding: id } = await params;
    const [{ i18n }, report] = await Promise.all([projectContext(project, 'audit'), runReport(project, run)]);
    const finding = report.findings.find((item) => String(item.id) === id) ?? notFound();
    const base = `/projects/${project}/audit`;

    return (
        <>
            <PageHeader i18n={i18n} title={finding.title} breadcrumbs={[{ label: t(i18n, 'Audits'), href: base }, { label: report.audit.name, href: `${base}/runs/${run}` }]} />
            <FindingView i18n={i18n} finding={finding} report={report} />
        </>
    );
}
