import { NewProjectWizard } from '@/components/projects/NewProjectWizard';
import { ModalPage } from '@/components/shell/ModalPage';
import { pageContext } from '@/lib/context';
import { t } from '@/lib/i18n';
import type { ServiceOption } from '@/lib/projects';
import { api } from '@/lib/server';

/** The new-project wizard in a modal over the page it was opened from. */
export default async function NewProjectModal() {
    const [{ i18n }, data] = await Promise.all([pageContext(), api<{ services: ServiceOption[] }>('/projects/new')]);

    return (
        <ModalPage i18n={i18n} title={t(i18n, 'New project')} size="wide">
            <NewProjectWizard services={data.services} />
        </ModalPage>
    );
}
