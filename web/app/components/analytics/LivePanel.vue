<script setup lang="ts">
import type { LiveVisitors } from '~/types/analytics';

/**
 * The "right now" panel: visitors in the last five minutes and what they did, refreshed every 15 seconds from `url`
 * (the report's address with ?live=1) while the tab is visible.
 */
const props = defineProps<{ recent: LiveVisitors; url: string }>();
const { t, tc, number } = useT();
const live = ref<LiveVisitors>(props.recent);
watch(() => props.recent, (recent) => {
    live.value = recent;
});
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(async () => {
        if (document.visibilityState !== 'visible') {
            return;
        }
        const result = await send<{ recent: LiveVisitors }>('GET', props.url, undefined, { signedOutRedirect: false }).catch(() => null);
        if (result?.recent) {
            live.value = result.recent;
        }
    }, 15000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section class="ui-card p-5" aria-labelledby="live-heading" aria-live="polite">
        <div class="flex items-center justify-between gap-3">
            <h2 id="live-heading" class="flex items-center gap-2 font-extrabold text-ink">
                <span class="relative flex size-2.5" aria-hidden="true">
                    <span v-if="live.visitorCount > 0" class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 motion-reduce:hidden" />
                    <span :class="['relative inline-flex size-2.5 rounded-full', live.visitorCount > 0 ? 'bg-success' : 'bg-line']" />
                </span>
                {{ t('Right now') }}
            </h2>
            <span class="text-sm font-bold text-ink tabular-nums">{{ tc(':count visitor in the last 5 minutes|:count visitors in the last 5 minutes', live.visitorCount, { count: number(live.visitorCount) }) }}</span>
        </div>
        <ul class="mt-3 divide-y divide-line text-sm">
            <li v-for="(event, index) in live.events" :key="index" class="flex items-center justify-between gap-4 py-2.5">
                <span class="min-w-0"><span class="block truncate font-bold text-ink">{{ event.path ?? '/' }}</span><span class="text-xs text-muted">{{ event.type }} · {{ event.source }}</span></span>
                <RelativeTime :at="event.occurredAt" class="shrink-0 text-xs text-muted" />
            </li>
            <li v-if="live.events.length === 0" class="py-2.5 text-muted">{{ t('No traffic in the last five minutes.') }}</li>
        </ul>
    </section>
</template>
