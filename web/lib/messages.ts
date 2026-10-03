import type { Messages, Translator } from './i18n';
import { locales } from './i18n';

/** Load the translations for a locale; English needs none. */
export async function translator(locale: string): Promise<Translator> {
    const known = (locales as readonly string[]).includes(locale) ? locale : 'en';
    const messages: Messages = known === 'en' ? {} : (await import(`../messages/${known}.json`)).default;

    return { locale: known, messages };
}
