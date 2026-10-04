<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** A CSV export: waiting while it's prepared (checking every few seconds), then the download. */
definePageMeta({ layout: 'app', service: 'analytics' });
type ExportPage = { overview: ProjectOverview; site: string; status: string; expiresAt: string; downloadUrl: string | null };
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<ExportPage>(() => `/projects/${route.params.project}/analytics/exports/${route.params.token}`);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (data.value.status === 'completed' || data.value.status === 'failed') {
            clearInterval(timer);
            return;
        }
        refreshPage();
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('CSV export')" :description="data.site" />
        <section class="ui-card grid gap-4 p-5 sm:p-6" aria-live="polite">
            <template v-if="data.status === 'completed' && data.downloadUrl">
                <p class="text-sm text-muted">{{ t('Ready. The download link works until :time.', { time: dateTime(data.expiresAt) }) }}</p>
                <div><a :href="data.downloadUrl" class="ui-btn ui-btn-primary" download><Icon name="arrow-down" class="h-4 w-4" />{{ t('Download CSV') }}</a></div>
            </template>
            <Alert v-else-if="data.status === 'failed'" tone="danger" role="alert">{{ t('The export failed. Try again from the report.') }}</Alert>
            <p v-else class="text-sm text-muted" role="status">{{ t('Your export is being prepared. This page updates when it’s ready.') }}</p>
        </section>
    </div>
</template>
