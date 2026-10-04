import type { Messages, Replacements, Translator } from '~/utils/i18n';

// The page's language and its translations, shared by every component (and sent to the browser with the page).

const loaders = import.meta.glob<{ default: Messages }>('../../messages/*.json');

/** The translator for this request: English until a language is set. */
function useTranslator() {
    return useState<Translator>('i18n', () => ({ locale: 'en', messages: {} }));
}

/** Switch the page to a language, loading its translations (English needs none). */
export async function setLocale(locale: string): Promise<void> {
    const state = useTranslator();
    const known = (locales as readonly string[]).includes(locale) ? locale : 'en';
    if (state.value.locale === known) {
        return;
    }
    const loader = loaders[`../../messages/${known}.json`];
    const messages = known === 'en' || !loader ? {} : (await loader()).default;
    state.value = { locale: known, messages };
}

/** Translate in a component: `const { t, tc } = useT()`, then `t('Save')` in the template or script. */
export function useT() {
    const state = useTranslator();
    // The browser's time zone, kept in a cookie by plugins/time-zone.client.ts, so the server formats dates the way the
    // browser will (until it's known, both use UTC).
    const timeZone = useCookie<string | undefined>('bp_tz', { readonly: true }).value || 'UTC';

    return {
        locale: computed(() => state.value.locale),
        t: (key: string, replacements?: Replacements) => translate(state.value, key, replacements),
        tc: (key: string, count: number, replacements?: Replacements) => translateChoice(state.value, key, count, replacements),
        /** Format a number for the language. */
        number: (value: number) => new Intl.NumberFormat(state.value.locale).format(value),
        /** Format a date and time for the language. */
        dateTime: (iso: string) => new Intl.DateTimeFormat(state.value.locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso)),
        /** Format a calendar date (no time) for the language, such as a due date. */
        date: (iso: string) => new Intl.DateTimeFormat(state.value.locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(iso)),
    };
}
