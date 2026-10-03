'use client';

import { createContext, Fragment, useContext, type ReactNode } from 'react';
import { t as translate, tc as translateChoice, type Replacements, type Translator } from './i18n';

const I18nContext = createContext<Translator>({ locale: 'en', messages: {} });

/** Give client components the page's translations. */
export function I18nProvider({ value, children }: { value: Translator; children: ReactNode }) {
    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

/** Get the translator in a client component: `const { t, tc } = useT(); t('Save')`. */
export function useT() {
    const i18n = useContext(I18nContext);

    return {
        i18n,
        t: (key: string, replacements?: Replacements) => translate(i18n, key, replacements),
        tc: (key: string, count: number, replacements?: Replacements) => translateChoice(i18n, key, count, replacements),
        /** Translate a sentence whose :placeholders are elements, such as links; parts maps each placeholder to its element. */
        rich: (key: string, parts: Record<string, ReactNode>) => {
            const text = translate(i18n, key);
            const names = Object.keys(parts).sort((a, b) => b.length - a.length);
            const pattern = new RegExp(`:(${names.join('|')})`, 'g');
            return text.split(pattern).map((piece, index) => <Fragment key={index}>{index % 2 === 1 ? parts[piece] : piece}</Fragment>);
        },
    };
}
