import type { Metadata } from 'next';
import { NewProjectWizard } from '@/components/projects/NewProjectWizard';
import { AppShell } from '@/components/shell/AppShell';
import { PageHeader } from '@/components/signal/PageHeader';
import { pageContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { ServiceOption } from '@/lib/projects';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'New project' };

/** The new-project wizard as a full page; from the app it opens as a modal. */
export default async function NewProjectPage() {
    const [{ shell, i18n }, data] = await Promise.all([pageContext(), api<{ services: ServiceOption[] }>('/projects/new')]);

    return (
        <AppShell shell={shell} i18n={i18n}>
            <PageHeader i18n={i18n} title={t(i18n, 'New project')} breadcrumbs={[{ label: t(i18n, 'Projects'), href: '/dashboard' }]} />
            <div className="ui-card max-w-3xl p-5 sm:p-6">
                <NewProjectWizard services={data.services} />
            </div>
        </AppShell>
    );
}
