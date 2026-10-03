import { cache } from 'react';
import { api } from './api';
import { translator } from './messages';
import type { Shell } from './types';

/**
 * The shell and translations for a page inside a project's service. Cached for the request, so the layout and the
 * page share one call to Laravel.
 */
export const projectContext = cache(async (projectId: string, service: string) => {
    const shell = await api<Shell>(`/projects/${encodeURIComponent(projectId)}/shell/${service}`);

    return { shell, i18n: await translator(shell.locale) };
});
