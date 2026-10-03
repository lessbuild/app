// Translation for the Next.js app, keyed by the English text exactly like Laravel's __(), so both frontends share
// api/lang. `npm run messages` copies the strings this app uses into messages/<locale>.json.

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
export function t(i18n: Translator, key: string, replacements: Replacements = {}): string {
    return replace(i18n.messages[key] ?? key, replacements);
}

/** Translate a string with singular and plural forms ("one|many"), like trans_choice. French uses the singular for 0. */
export function tc(i18n: Translator, key: string, count: number, replacements: Replacements = {}): string {
    const forms = (i18n.messages[key] ?? key).split('|');
    const plural = i18n.locale === 'fr' ? count > 1 : count !== 1;

    return replace((plural ? forms[1] : forms[0]) ?? forms[0] ?? key, { count, ...replacements });
}

/** Format a number for the locale. */
export function number(i18n: Translator, value: number): string {
    return new Intl.NumberFormat(i18n.locale).format(value);
}

/** Format a date and time for the locale. */
export function dateTime(i18n: Translator, iso: string): string {
    return new Intl.DateTimeFormat(i18n.locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}
