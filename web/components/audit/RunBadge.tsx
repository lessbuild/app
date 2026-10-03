import { Badge, type Tone } from '@/components/signal/Badge';
import { t, type Translator } from '@/lib/i18n';
import type { RunSummary } from '@/lib/types';

/** A run's state, or its score once done, with words as well as colour. */
export function RunBadge({ i18n, run }: { i18n: Translator; run: RunSummary }) {
    if (run.status === 'done' && run.score !== null) {
        return <Badge tone={scoreTone(run.score)}>{t(i18n, 'Score :score', { score: run.score })}</Badge>;
    }
    const states: Record<RunSummary['status'], [string, Tone]> = {
        queued: [t(i18n, 'Waiting to start'), 'neutral'],
        running: [t(i18n, 'Running'), 'info'],
        done: [t(i18n, 'Done'), 'success'],
        failed: [t(i18n, 'Failed'), 'danger'],
    };
    const [label, tone] = states[run.status];

    return <Badge tone={tone}>{label}</Badge>;
}

/** The tone for a 0–100 score: good from 80, fair from 50. */
export function scoreTone(score: number): Tone {
    return score >= 80 ? 'success' : score >= 50 ? 'warning' : 'danger';
}
