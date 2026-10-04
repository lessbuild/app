<script setup lang="ts">
/** BuildPusher's own status: each part of the platform and whether it's working (or the status page set for it). */
definePageMeta({ layout: 'public' });
type PlatformStatus = { redirect?: string; operational: boolean; checkedAt: string; components: Array<{ name: string; description: string; status: string; operational: boolean }>; reportUrl: string };
const { t, dateTime } = useT();
const { data } = await useApi<PlatformStatus>('/platform-status');
if (data.value.redirect) {
    await navigateTo(data.value.redirect, { replace: true });
}
useHead({ title: () => t(':app status', { app: 'BuildPusher' }), meta: [{ name: 'description', content: () => t('Live status of the platform and its services.') }, { name: 'robots', content: 'index, follow' }] });
</script>

<template>
    <div v-if="!data.redirect">
        <SiteHero kicker="BuildPusher" :title="t('Platform status')" />
        <SiteSection tint frame-class="grid gap-6">
            <section aria-live="polite" :class="['flex items-center gap-4 rounded-xl border p-5 sm:p-6', data.operational ? 'border-success bg-success-soft' : 'border-warning bg-warning-soft']">
                <StatusDot size="lg" :color="data.operational ? 'var(--ui-success)' : 'var(--ui-warning)'" aria-hidden="true" />
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ data.operational ? t('All systems operational') : t('Some systems are degraded') }}</h2>
                    <p class="mt-0.5 text-sm text-muted">{{ t('Checked :time', { time: dateTime(data.checkedAt) }) }}</p>
                </div>
            </section>
            <section class="site-card overflow-hidden" :aria-label="t('Platform status')">
                <ul class="divide-y divide-line">
                    <li v-for="part in data.components" :key="part.name" class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0"><p class="font-semibold text-ink">{{ part.name }}</p><p class="text-sm text-muted">{{ part.description }}</p></div>
                        <Badge :tone="part.operational ? 'success' : 'warning'">{{ part.status }}</Badge>
                    </li>
                </ul>
            </section>
            <p class="text-xs text-muted"><a :href="data.reportUrl" class="underline">{{ t('JSON report') }}</a></p>
        </SiteSection>
    </div>
</template>
