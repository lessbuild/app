import type { ReactNode } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { projectContext } from '@/lib/context';

/** Audit's pages inside a project: the signed-in frame, and a slot for the pages that open as modals. */
export default async function AuditLayout({ children, modal, params }: { children: ReactNode; modal: ReactNode; params: Promise<{ project: string }> }) {
    const { project } = await params;
    const { shell, i18n } = await projectContext(project, 'audit');

    return (
        <AppShell shell={shell} i18n={i18n} service="audit">
            {children}
            {modal}
        </AppShell>
    );
}
