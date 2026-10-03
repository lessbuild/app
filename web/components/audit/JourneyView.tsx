import { Badge } from '@/components/signal/Badge';
import { t, tc, type Translator } from '@/lib/i18n';
import type { Journey, Report } from '@/lib/types';
import { Screenshot } from './Screenshot';

/** A journey replayed: how it ended, the friction, and every step with its screen and the visitor's reasoning. */
export function JourneyView({ i18n, journey, report }: { i18n: Translator; journey: Journey; report: Report }) {
    const tone = { succeeded: 'success', struggled: 'warning', failed: 'danger' } as const;
    const verbs: Record<string, string> = {
        click: t(i18n, 'Clicked'), type: t(i18n, 'Typed'), select: t(i18n, 'Chose'), press_enter: t(i18n, 'Pressed Enter'),
        scroll: t(i18n, 'Scrolled'), back: t(i18n, 'Went back'), finish: t(i18n, 'Finished'), give_up: t(i18n, 'Gave up'),
    };

    return (
        <article className="grid gap-6">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted">
                <Badge tone={tone[journey.outcome]}>{journey.outcomeLabel}</Badge>
                <span>{journey.siteName}</span>
                <span aria-hidden="true">·</span>
                <span>{tc(i18n, ':count step|:count steps', journey.stepsCount)}</span>
                <span aria-hidden="true">·</span>
                <span>{t(i18n, ':seconds s', { seconds: journey.seconds })}</span>
            </div>
            {journey.summary && <blockquote className="border-l-4 border-line pl-4 text-sm italic leading-6 text-ink">{journey.summary}</blockquote>}
            {journey.friction.length > 0 && (
                <section className="grid gap-2">
                    <h3 className="text-sm font-extrabold text-ink">{t(i18n, 'What slowed the visitor down')}</h3>
                    <ul className="grid list-disc gap-1 pl-5 text-sm text-muted">
                        {journey.friction.map((item) => <li key={item}>{item}</li>)}
                    </ul>
                </section>
            )}
            <ol className="grid gap-5">
                {journey.steps.map((step) => (
                    <li key={step.position} className="grid gap-3 border-t border-line pt-5 md:grid-cols-[16rem_1fr]">
                        <div className="grid content-start gap-1">
                            <p className="ui-eyebrow">{t(i18n, 'Step :number', { number: step.position })}</p>
                            <p className="text-sm font-bold text-ink">
                                {verbs[step.action.type] ?? step.action.type}
                                {step.action.label ? ` “${step.action.label}”` : ''}
                                {step.action.text ? ` — “${step.action.text}”` : ''}
                            </p>
                            {step.thought && <p className="text-sm leading-6 text-muted">{step.thought}</p>}
                            <p className="truncate text-xs text-subtle">{step.url}</p>
                        </div>
                        {step.screenshotUrl && <Screenshot src={step.screenshotUrl} alt={t(i18n, 'The screen at step :number', { number: step.position })} boxes={step.boxes} screen={report.screen} />}
                    </li>
                ))}
            </ol>
        </article>
    );
}
