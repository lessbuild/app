<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** A read-only health check of a server, and what's taking disk space that can be cleared safely. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const bytes = useFileSize();
const diagnostics = computed(() => props.page.diagnostics);
const disk = computed(() => props.page.diskScan);
const busy = computed(() => diagnostics.value?.running === true || ['queued', 'running'].includes(disk.value?.status ?? ''));
let timer: number | undefined;

// While a check or a measurement runs, look again every few seconds.
watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 5000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <AcmeCard id="diagnostics" :padded="false" :title="t('Diagnostics')" :description="t('A read-only check of SSH, root access, PHP, storage, disk, memory and processes.')">
            <div class="grid gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <p v-if="diagnostics?.finishedAt" class="text-xs text-muted"><Rich :text="t('Last run :time')"><template #time><RelativeTime :at="diagnostics.finishedAt" /></template></Rich></p>
                <p v-if="diagnostics?.running" class="text-sm text-muted" role="status">{{ t('Running… refresh in a few seconds.') }}</p>
                <ul v-else-if="diagnostics && diagnostics.checks.length > 0" class="grid gap-2">
                    <li v-for="check in diagnostics.checks" :key="check.name" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="font-bold text-ink">{{ check.name }}</span>
                        <span class="flex items-center gap-2 text-muted">{{ check.detail }} <AcmeBadge :tone="acmeTone(check.passed ? 'success' : 'danger')">{{ check.passed ? t('OK') : t('Problem') }}</AcmeBadge></span>
                    </li>
                </ul>
                <ApiForm :action="`${base}/diagnostics`"><SubmitButton variant="secondary" size="sm">{{ t('Run diagnostics') }}</SubmitButton></ApiForm>
            </div>
        </AcmeCard>
        <AcmeCard
id="disk"
            :padded="false"
            :title="t('Disk clean-up')"
            :description="t('What’s taking space that can be cleared safely: releases beyond the ones each website keeps (never the live one), logs older than two weeks, package caches, unused Docker images and old temporary files.')"
        >
            <div class="grid gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <p v-if="disk && disk.free !== null && disk.size !== null" class="text-sm text-muted">
                    {{ t(':free free of :size', { free: bytes(disk.free), size: bytes(disk.size) }) }}
                    <template v-if="disk.scannedAt"> · <Rich :text="t('measured :time')"><template #time><RelativeTime :at="disk.scannedAt" /></template></Rich></template>
                </p>
                <AcmeAlert v-if="disk?.error" tone="danger">{{ disk.error }}</AcmeAlert>
                <p v-if="disk && ['queued', 'running'].includes(disk.status)" class="text-sm text-muted" role="status">{{ t('Working… refresh in a moment.') }}</p>
                <ul v-if="disk && disk.categories.length > 0" class="grid gap-2 text-sm">
                    <li v-for="category in disk.categories" :key="category.key" class="flex flex-wrap items-center justify-between gap-3">
                        <span><span class="font-bold text-ink">{{ category.label }}</span> <span class="text-muted">· {{ bytes(category.bytes) }}</span></span>
                        <ApiForm v-if="page.canRunCommands && category.bytes > 0" :action="`${base}/disk`">
                            <input type="hidden" name="clean" :value="category.key">
                            <SubmitButton variant="secondary" size="sm">{{ t('Clear') }}</SubmitButton>
                        </ApiForm>
                    </li>
                </ul>
                <ApiForm v-if="page.canRunCommands" :action="`${base}/disk`"><SubmitButton variant="quiet" size="sm">{{ disk ? t('Measure again') : t('Measure the disk') }}</SubmitButton></ApiForm>
            </div>
        </AcmeCard>
    </div>
</template>
