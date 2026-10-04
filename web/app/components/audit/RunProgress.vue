<script setup lang="ts">
import type { AuditReport } from '~/types/audit';

/** While a run is going: what it's done so far, checked every few seconds; the page reloads once it's finished. */
const props = defineProps<{ projectId: string; report: AuditReport }>();
const { t, tc } = useT();
const report = ref(props.report);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(async () => {
        const next = await send<{ report: AuditReport }>('GET', `/projects/${props.projectId}/audit/runs/${props.report.run.id}`).catch(() => null);
        if (!next) {
            return;
        }
        report.value = next.report;
        if (next.report.run.status === 'done' || next.report.run.status === 'failed') {
            clearInterval(timer);
            await refreshPage();
        }
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));
const latest = computed(() => report.value.journeys.at(-1));
</script>

<template>
    <div class="ui-card grid gap-5 p-6" role="status" aria-live="polite">
        <div class="flex items-center gap-3">
            <span class="ui-spinner size-5" aria-hidden="true" />
            <h2 class="text-lg font-extrabold text-ink">{{ report.run.status === 'queued' ? t('Waiting to start…') : t('The visitor is trying your site…') }}</h2>
        </div>
        <p class="text-sm text-muted">{{ t('Each task is tried on your site and on every competitor’s, then the findings are written. This usually takes a few minutes; you can leave this page and come back.') }}</p>
        <dl class="grid grid-cols-2 gap-3 sm:max-w-md">
            <div class="ui-stat"><dt class="text-xs font-bold text-muted">{{ t('Journeys') }}</dt><dd class="mt-2 text-2xl font-extrabold text-ink">{{ report.progress.journeys }}</dd></div>
            <div class="ui-stat"><dt class="text-xs font-bold text-muted">{{ t('Pages visited') }}</dt><dd class="mt-2 text-2xl font-extrabold text-ink">{{ report.progress.pages }}</dd></div>
        </dl>
        <p v-if="latest" class="text-sm text-muted">
            {{ t('Latest: :goal on :site', { goal: latest.goal, site: latest.siteName }) }} · {{ tc(':count step|:count steps', latest.steps.length, { count: latest.steps.length }) }}
            <template v-if="latest.steps.at(-1)?.thought"> · “{{ latest.steps.at(-1)?.thought }}”</template>
        </p>
    </div>
</template>
