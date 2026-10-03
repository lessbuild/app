'use client';

import { usePathname } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/signal/Icon';
import { EVENT, takeFlash } from '@/lib/flash';
import { useT } from '@/lib/i18n-client';

type Toast = { message: string; tone: string };

/**
 * Shows the message an action left (after a navigation, or on the same page after a save), announced to screen
 * readers, for a few seconds.
 */
export function Toaster() {
    const { t } = useT();
    const pathname = usePathname();
    const [toast, setToast] = useState<Toast | null>(null);
    const timer = useRef<number | undefined>(undefined);

    const present = useCallback(() => {
        const next = takeFlash();
        if (next) {
            setToast(next);
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(() => setToast(null), 6000);
        }
    }, []);

    // A message for this page (a save that refreshed it) arrives as an event.
    useEffect(() => {
        window.addEventListener(EVENT, present);
        return () => window.removeEventListener(EVENT, present);
    }, [present]);

    // A message carried across a navigation is waiting when the new page appears.
    useEffect(() => {
        const frame = window.requestAnimationFrame(present);
        return () => window.cancelAnimationFrame(frame);
    }, [pathname, present]);

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center px-4" aria-live="polite" role="status">
            {toast && (
                <div className={`ui-alert ui-alert--${toast.tone} ui-alert-${toast.tone} pointer-events-auto flex max-w-lg items-start gap-3 shadow-panel`}>
                    <span className="text-sm">{toast.message}</span>
                    <button type="button" className="ui-icon-btn ui-icon-btn-sm -my-1 -mr-1 shrink-0" aria-label={t('Dismiss')} onClick={() => setToast(null)}>
                        <Icon name="close" className="h-4 w-4" />
                    </button>
                </div>
            )}
        </div>
    );
}
