// Translation keyed by the English text exactly like Laravel's __(), so the app and the API share api/lang.
// `npm run messages` copies the strings this app uses into messages/<locale>.json. Components use useT().

export type Messages = Record<string, string>;
export type Translator = { locale: string; messages: Messages };
export type Replacements = Record<string, string | number>;

export const locales = ['en', 'es', 'fr', 'de', 'pt'] as const;

/** Swap :placeholders, longest first, also matching :Placeholder and :PLACEHOLDER the way Laravel does. */
function replace(text: string, replacements: Replacements): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce((result, key) => {
            const value = String(replacements[key]);
            return result
                .replaceAll(`:${key.toUpperCase()}`, value.toUpperCase())
                .replaceAll(`:${key[0]?.toUpperCase()}${key.slice(1)}`, value[0] ? value[0].toUpperCase() + value.slice(1) : value)
                .replaceAll(`:${key}`, value);
        }, text);
}

/** Translate a string, falling back to the English. */
export function translate(i18n: Translator, key: string, replacements: Replacements = {}): string {
    return replace(i18n.messages[key] ?? key, replacements);
}

/** Translate a string with singular and plural forms ("one|many"), like trans_choice. French uses the singular for 0. */
export function translateChoice(i18n: Translator, key: string, count: number, replacements: Replacements = {}): string {
    const forms = (i18n.messages[key] ?? key).split('|');
    const plural = i18n.locale === 'fr' ? count > 1 : count !== 1;

    return replace((plural ? forms[1] : forms[0]) ?? forms[0] ?? key, { count, ...replacements });
}

/** The best supported language for an Accept-Language header, else English. */
export function preferredLocale(header: string | undefined): string {
    const accepted = (header ?? '')
        .split(',')
        .map((part) => {
            const [tag, q] = part.trim().split(';q=');
            return { language: (tag ?? '').slice(0, 2).toLowerCase(), weight: q ? Number(q) : 1 };
        })
        .sort((a, b) => b.weight - a.weight);

    return accepted.find((entry) => (locales as readonly string[]).includes(entry.language))?.language ?? 'en';
}

/** How long ago something happened, in words, for the language. */
export function ago(iso: string, locale: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [['day', 86400], ['hour', 3600], ['minute', 60]];
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }
    return format.format(seconds, 'second');
}
