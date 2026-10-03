import { headers } from 'next/headers';
import { locales, type Translator } from './i18n';
import { translator } from './messages';

/** The translations for someone who isn't signed in: the best match for their browser's languages, else English. */
export async function guestTranslator(): Promise<Translator> {
    const accepted = ((await headers()).get('accept-language') ?? '')
        .split(',')
        .map((part) => {
            const [tag, q] = part.trim().split(';q=');
            return { language: (tag ?? '').slice(0, 2).toLowerCase(), weight: q ? Number(q) : 1 };
        })
        .sort((a, b) => b.weight - a.weight);
    const match = accepted.find((entry) => (locales as readonly string[]).includes(entry.language));

    return translator(match?.language ?? 'en');
}
