import { cache } from 'react';
import { translator } from './messages';
import { api } from './server';
import type { Shell } from './types';

/**
 * The shell (the person, their accounts and projects, the navigation) and translations for a signed-in page, inside a
 * project and service or not. Cached for the request, so layouts and the page share one call to Laravel.
 */
export const pageContext = cache(async (project?: string, service?: string) => {
    const shell = await api<Shell>('/shell', { project, service });

    return { shell, i18n: await translator(shell.locale) };
});
