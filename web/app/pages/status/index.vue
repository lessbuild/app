<script setup lang="ts">
/**
 * BuildPusher's own status (the Acme theme's status page): each part of the platform and whether it's working, or the
 * status page set for it.
 */
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
    <div v-if="!data.redirect" class="mx-auto max-w-3xl space-y-10 px-5 py-10">
        <h1 class="sr-only">{{ t('Platform status') }}</h1>
        <div :class="['flex flex-wrap items-center gap-3 rounded-xl px-5 py-4 text-white', data.operational ? 'bg-emerald-600' : 'bg-amber-500']" role="status" aria-live="polite">
            <AcmeIcon :name="data.operational ? 'checkCircle' : 'alert'" :size="22" />
            <p class="font-semibold">{{ data.operational ? t('All systems operational') : t('Some systems are degraded') }}</p>
            <span class="ml-auto text-sm opacity-80">{{ t('Checked :time', { time: dateTime(data.checkedAt) }) }}</span>
        </div>
        <section class="rounded-2xl border border-line bg-surface shadow-card" :aria-label="t('Platform status')">
            <ul class="divide-y divide-line">
                <li v-for="part in data.components" :key="part.name" class="space-y-1 p-5">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-ink">{{ part.name }}</span>
                        <span :class="['flex items-center gap-1.5', part.operational ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400']"><AcmeIcon :name="part.operational ? 'checkCircle' : 'alert'" :size="15" />{{ part.status }}</span>
                    </div>
                    <p class="text-sm text-muted">{{ part.description }}</p>
                </li>
            </ul>
        </section>
        <p class="text-center text-sm text-muted"><a :href="data.reportUrl" class="underline">{{ t('JSON report') }}</a></p>
    </div>
</template>
