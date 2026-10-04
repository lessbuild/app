// Labels for an audit finding's severity and effort, in the person's language.

/** The words and tones for each severity and effort. */
export function useFindingLabels() {
    const { t } = useT();

    return {
        severity: { high: t('High impact'), medium: t('Medium impact'), low: t('Low impact') },
        severityTone: { high: 'danger', medium: 'warning', low: 'neutral' } as const,
        effort: { small: t('Small change'), medium: t('Medium change'), large: t('Large change') },
    };
}
