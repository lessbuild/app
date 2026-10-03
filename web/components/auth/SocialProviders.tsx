'use client';

import { useT } from '@/lib/i18n-client';

/** "Or continue with" and a button per social provider set up here. Links go to Laravel, which runs the round trip. */
export function SocialProviders({ providers }: { providers: Array<{ key: string; label: string; url: string }> }) {
    const { t } = useT();
    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3">
            <div className="flex items-center gap-3 text-xs font-bold uppercase tracking-wide text-muted" aria-hidden="true">
                <span className="h-px flex-1 bg-line" />
                {t('Or continue with')}
                <span className="h-px flex-1 bg-line" />
            </div>
            <div className={`grid gap-2 ${providers.length === 2 ? 'sm:grid-cols-2' : providers.length >= 3 ? 'sm:grid-cols-3' : ''}`}>
                {providers.map((provider) => (
                    <a key={provider.key} href={provider.url} className="ui-btn ui-btn-secondary w-full justify-center">{provider.label}</a>
                ))}
            </div>
        </div>
    );
}
