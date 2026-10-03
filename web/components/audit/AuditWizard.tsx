'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Button } from '@/components/signal/Button';
import { Field, Input, Textarea, describedBy } from '@/components/signal/Field';
import { Wizard, type WizardStep } from '@/components/signal/Wizard';
import { send, ValidationError } from '@/lib/client';
import { useT } from '@/lib/i18n-client';
import type { AuditDetail, AuditPlan, Competitor, Goal } from '@/lib/types';

type Suggestion = { name: string; url: string; reason: string };

// Which step each server-side field belongs to, so an error from Laravel takes people back to the right step.
const stepOfField: Record<string, number> = { name: 0, url: 0, goals: 1, custom_goal: 1, competitors: 2, schedule: 3, audit: 3 };

/**
 * Set up an audit in four steps (the site, the tasks the visitor tries, the competitors, then the schedule and a
 * review), or change one. Used full-page and in the New audit modal.
 */
export function AuditWizard({ projectId, plan, goals, audit }: { projectId: string; plan: AuditPlan; goals: Goal[]; audit?: AuditDetail }) {
    const { t, tc } = useT();
    const router = useRouter();
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<ValidationError | null>(null);

    const [name, setName] = useState(audit?.name ?? '');
    const [url, setUrl] = useState(audit?.url ?? '');
    const [chosen, setChosen] = useState<string[]>(audit ? audit.journeys.filter((j) => j.key !== 'custom').map((j) => j.key) : ['understand', 'pricing', 'sign_up']);
    const [customGoal, setCustomGoal] = useState(audit?.journeys.find((j) => j.key === 'custom')?.goal ?? '');
    const [competitors, setCompetitors] = useState<Competitor[]>(audit?.competitors ?? []);
    const [newCompetitor, setNewCompetitor] = useState('');
    const [suggestions, setSuggestions] = useState<Suggestion[] | null>(null);
    const [suggesting, setSuggesting] = useState(false);
    const [suggestError, setSuggestError] = useState<string | null>(null);
    const [schedule, setSchedule] = useState(audit?.schedule ?? 'none');

    const limit = plan.competitorLimit;
    const atLimit = limit !== null && competitors.length >= limit;
    const taskCount = chosen.length + (customGoal.trim() ? 1 : 0);
    const fieldError = (field: string) => fieldErrors?.first(field);

    function addCompetitor(competitor: Competitor) {
        const host = (value: string) => value.replace(/^https?:\/\//, '').replace(/\/.*$/, '').toLowerCase();
        if (atLimit || competitors.some((existing) => host(existing.url) === host(competitor.url)) || host(competitor.url) === host(url)) {
            return;
        }
        setCompetitors([...competitors, competitor]);
    }

    async function suggest() {
        setSuggesting(true);
        setSuggestError(null);
        try {
            const result = await send<{ competitors: Suggestion[] }>('POST', `/projects/${projectId}/audit/competitor-suggestions`, { url });
            setSuggestions(result.competitors);
        } catch (problem) {
            setSuggestError(problem instanceof ValidationError ? (problem.first('url') ?? problem.message) : t('Suggestions aren’t available right now. Add competitors yourself, or try again.'));
        } finally {
            setSuggesting(false);
        }
    }

    async function finish() {
        setBusy(true);
        setError(null);
        setFieldErrors(null);
        const body = {
            name, url, goals: chosen, custom_goal: customGoal.trim() || null, schedule,
            competitors: competitors.map((competitor) => ({ url: competitor.url, name: competitor.name, source: competitor.source ?? 'customer', reason: competitor.reason })),
        };
        try {
            if (audit) {
                await send('PUT', `/projects/${projectId}/audit/${audit.id}`, body);
                router.back();
                router.refresh();
            } else {
                const created = await send<{ audit: AuditDetail; runId: number | null }>('POST', `/projects/${projectId}/audit`, body);
                // Leave the modal and open the run, which shows its progress until the report is ready.
                window.location.href = created.runId ? `/projects/${projectId}/audit/runs/${created.runId}` : `/projects/${projectId}/audit/${created.audit.id}`;
            }
        } catch (problem) {
            if (problem instanceof ValidationError) {
                setFieldErrors(problem);
                const first = Object.keys(problem.errors)[0]?.split('.')[0] ?? 'audit';
                setStep(stepOfField[first] ?? 3);
                setError(problem.message);
            } else {
                setError(problem instanceof Error ? problem.message : t('Something went wrong. Try again.'));
            }
            setBusy(false);
        }
    }

    const steps: WizardStep[] = [
        {
            id: 'site',
            title: t('Your site'),
            description: t('The page the visitor starts on, usually your home page.'),
            validate: () => (url.trim() === '' ? t('Enter your site’s address.') : null),
            content: (
                <div className="grid gap-4">
                    <Field id="audit-url" label={t('Website address')} error={fieldError('url')}>
                        <Input id="audit-url" value={url} onChange={(event) => setUrl(event.target.value)} placeholder="example.com" inputMode="url" autoComplete="url" required invalid={!!fieldError('url')} aria-describedby={describedBy('audit-url', null, fieldError('url'))} />
                    </Field>
                    <Field id="audit-name" label={t('Name')} description={t('Optional. Shown on the report; the address is used if you leave it empty.')} error={fieldError('name')}>
                        <Input id="audit-name" value={name} onChange={(event) => setName(event.target.value)} maxLength={120} aria-describedby={describedBy('audit-name', true, fieldError('name'))} />
                    </Field>
                </div>
            ),
        },
        {
            id: 'tasks',
            title: t('What should the visitor try?'),
            description: t('Pick up to five tasks. The visitor tries each one on your site and on every competitor’s.'),
            validate: () => (taskCount === 0 ? t('Choose at least one task.') : taskCount > 5 ? t('Choose at most five tasks.') : null),
            content: (
                <div className="grid gap-4">
                    <fieldset className="grid gap-2 sm:grid-cols-2">
                        <legend className="sr-only">{t('Tasks')}</legend>
                        {goals.filter((goal) => goal.value !== 'custom').map((goal) => (
                            <label key={goal.value} className="ui-choice flex cursor-pointer items-center gap-3 rounded-control border border-line p-3 text-sm font-semibold text-ink has-[:checked]:border-primary has-[:checked]:bg-primary-soft">
                                <input
                                    type="checkbox"
                                    className="ui-check"
                                    checked={chosen.includes(goal.value)}
                                    onChange={(event) => setChosen(event.target.checked ? [...chosen, goal.value] : chosen.filter((value) => value !== goal.value))}
                                />
                                {goal.label}
                            </label>
                        ))}
                    </fieldset>
                    <Field id="audit-custom-goal" label={t('Your own task')} description={t('Optional. Describe it as a visitor would, such as “Find out whether you deliver to Ireland.”')} error={fieldError('custom_goal')}>
                        <Textarea id="audit-custom-goal" rows={2} maxLength={300} value={customGoal} onChange={(event) => setCustomGoal(event.target.value)} aria-describedby={describedBy('audit-custom-goal', true, fieldError('custom_goal'))} />
                    </Field>
                    <p className="text-xs font-semibold text-muted" aria-live="polite">{tc(':count task chosen|:count tasks chosen', taskCount)}</p>
                </div>
            ),
        },
        {
            id: 'competitors',
            title: t('Who do people compare you with?'),
            description: limit === null
                ? t('Add the sites your visitors weigh you against. The same tasks run on each.')
                : tc('Your plan compares with up to :count competitor.|Your plan compares with up to :count competitors.', limit),
            content: (
                <div className="grid gap-4">
                    {competitors.length > 0 && (
                        <ul className="grid gap-2" aria-label={t('Competitors')}>
                            {competitors.map((competitor) => (
                                <li key={competitor.url} className="flex items-center justify-between gap-3 rounded-control border border-line p-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-bold text-ink">{competitor.name}</p>
                                        <p className="truncate text-xs text-muted">{competitor.url}</p>
                                    </div>
                                    <Button size="sm" variant="quiet" onClick={() => setCompetitors(competitors.filter((existing) => existing.url !== competitor.url))} aria-label={t('Remove :name', { name: competitor.name })}>
                                        {t('Remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap items-end gap-2">
                        <Field id="audit-competitor" label={t('Competitor’s address')} className="min-w-56 flex-1" error={fieldError('competitors')}>
                            <Input
                                id="audit-competitor"
                                value={newCompetitor}
                                disabled={atLimit}
                                placeholder="competitor.com"
                                inputMode="url"
                                onChange={(event) => setNewCompetitor(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        if (newCompetitor.trim()) {
                                            addCompetitor({ url: newCompetitor.trim(), name: newCompetitor.trim().replace(/^https?:\/\//, '').replace(/\/.*$/, ''), source: 'customer', reason: null });
                                            setNewCompetitor('');
                                        }
                                    }
                                }}
                            />
                        </Field>
                        <Button
                            disabled={atLimit || !newCompetitor.trim()}
                            onClick={() => {
                                addCompetitor({ url: newCompetitor.trim(), name: newCompetitor.trim().replace(/^https?:\/\//, '').replace(/\/.*$/, ''), source: 'customer', reason: null });
                                setNewCompetitor('');
                            }}
                        >
                            {t('Add')}
                        </Button>
                        <Button variant="soft" onClick={suggest} disabled={suggesting || url.trim() === ''} aria-busy={suggesting || undefined}>
                            {suggesting ? t('Looking…') : t('Suggest competitors')}
                        </Button>
                    </div>
                    {suggestError && <p className="ui-error" role="alert">{suggestError}</p>}
                    {suggestions && (
                        <div className="grid gap-2" aria-live="polite">
                            <p className="text-xs font-bold uppercase tracking-[0.14em] text-subtle">{t('Suggestions')}</p>
                            {suggestions.length === 0 && <p className="text-sm text-muted">{t('No suggestions this time. Add competitors yourself.')}</p>}
                            {suggestions.map((suggestion) => {
                                const added = competitors.some((competitor) => competitor.url === suggestion.url);
                                return (
                                    <div key={suggestion.url} className="flex items-start justify-between gap-3 rounded-control border border-dashed border-line p-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-bold text-ink">{suggestion.name} <span className="font-normal text-muted">{suggestion.url}</span></p>
                                            <p className="mt-0.5 text-xs text-muted">{suggestion.reason}</p>
                                        </div>
                                        <Button size="sm" disabled={added || atLimit} onClick={() => addCompetitor({ url: suggestion.url, name: suggestion.name, source: 'suggested', reason: suggestion.reason })}>
                                            {added ? t('Added') : t('Add')}
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {competitors.length === 0 && <p className="text-xs text-muted">{t('You can skip this: the report then scores your site on its own.')}</p>}
                </div>
            ),
        },
        {
            id: 'review',
            title: t('Schedule and review'),
            description: t('Check the details, then start the audit. The first report takes a few minutes.'),
            content: (
                <div className="grid gap-5">
                    <fieldset className="grid gap-2">
                        <legend className="ui-label mb-1">{t('Run it again automatically')}</legend>
                        {[
                            ['none', t('Only when I run it')],
                            ['monthly', t('Every month')],
                            ['weekly', t('Every week')],
                        ].map(([value, label]) => {
                            const allowed = plan.schedules.includes(value as string);
                            return (
                                <label key={value} className={`flex items-center gap-3 rounded-control border border-line p-3 text-sm font-semibold ${allowed ? 'cursor-pointer text-ink' : 'text-subtle'}`}>
                                    <input type="radio" name="schedule" value={value} checked={schedule === value} disabled={!allowed} onChange={() => setSchedule(value as string)} />
                                    {label}
                                    {!allowed && <span className="ml-auto text-xs font-bold">{value === 'weekly' ? t('Business plan') : t('Pro plan')}</span>}
                                </label>
                            );
                        })}
                        {fieldError('schedule') && <p className="ui-error" role="alert">{fieldError('schedule')}</p>}
                    </fieldset>
                    <dl className="grid gap-3 rounded-card bg-surface-muted p-4 text-sm sm:grid-cols-[9rem_1fr]">
                        <dt className="font-bold text-muted">{t('Site')}</dt>
                        <dd className="text-ink">{name || url}</dd>
                        <dt className="font-bold text-muted">{t('Tasks')}</dt>
                        <dd className="text-ink">
                            {[...goals.filter((goal) => chosen.includes(goal.value)).map((goal) => goal.label), ...(customGoal.trim() ? [customGoal.trim()] : [])].join(' · ')}
                        </dd>
                        <dt className="font-bold text-muted">{t('Competitors')}</dt>
                        <dd className="text-ink">{competitors.length ? competitors.map((competitor) => competitor.name).join(' · ') : t('None')}</dd>
                    </dl>
                    {!audit && plan.runsAllowance !== null && (
                        <p className="text-xs text-muted">{tc('This uses your one audit this month.|This uses one of your :count audits this month (:used used so far).', plan.runsAllowance, { used: plan.runsUsed })}</p>
                    )}
                </div>
            ),
        },
    ];

    return (
        <Wizard
            steps={steps}
            current={step}
            onStepChange={setStep}
            busy={busy}
            error={error}
            finishLabel={audit ? t('Save changes') : t('Start the audit')}
            onFinish={finish}
        />
    );
}
