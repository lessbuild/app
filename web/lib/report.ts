import { cache } from 'react';
import { api } from './api';
import type { Report } from './types';

/** A run's report, fetched once per request for the page and any modal over it. */
export const runReport = cache(async (projectId: string, runId: string) => (await api<{ report: Report }>(`/projects/${projectId}/audit/runs/${runId}`)).report);
