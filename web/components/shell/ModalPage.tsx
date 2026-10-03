import type { ReactNode } from 'react';
import { Modal } from '@/components/signal/Modal';
import type { Translator } from '@/lib/i18n';
import { I18nProvider } from '@/lib/i18n-client';

/** A screen shown as a modal over the page it was opened from (an intercepted route), with its translations. */
export function ModalPage({ i18n, title, description, size, children }: { i18n: Translator; title: string; description?: string; size?: 'default' | 'wide' | 'large'; children: ReactNode }) {
    return (
        <I18nProvider value={i18n}>
            <Modal title={title} description={description} size={size}>{children}</Modal>
        </I18nProvider>
    );
}
