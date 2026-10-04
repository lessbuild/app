// Words for Deploy's stored values (what started a deploy, what became of a push), in the person's language.

/** Labels for how a deploy started and what became of a push webhook. */
export function useDeployLabels() {
    const { t } = useT();
    const triggers = computed<Record<string, string>>(() => ({
        manual: t('Manual'),
        api: t('API'),
        webhook: t('Push'),
        scheduled: t('Scheduled'),
        rollback: t('Rollback'),
        promotion: t('Promotion'),
        preview: t('Preview'),
    }));
    const deliveries = computed<Record<string, string>>(() => ({
        received: t('Received'),
        queued: t('Deployed'),
        pending: t('Waiting behind a deploy'),
        skipped: t('Skipped: no matching paths'),
        unavailable: t('Not ready to deploy'),
    }));

    return {
        /** How long something took, such as "2 min 5 s" or "40 s". */
        duration: (seconds: number | null) => {
            if (seconds === null) {
                return '—';
            }
            const minutes = Math.floor(seconds / 60);
            return minutes > 0 ? t(':minutes min :seconds s', { minutes, seconds: seconds % 60 }) : t(':seconds s', { seconds });
        },
        /** What started a deploy, such as "Push" or "Rollback". */
        trigger: (source: string) => triggers.value[source] ?? source,
        /** What became of a push, such as "Deployed" or "Skipped: no matching paths". */
        delivery: (status: string) => deliveries.value[status] ?? status,
    };
}
