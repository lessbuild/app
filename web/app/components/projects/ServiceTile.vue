<script setup lang="ts">
import type { ServiceCard } from '~/types/projects';

/**
 * One service on the project overview (the Acme theme's service card): its first sections when it's on, turning it on
 * when it's off, or who can.
 */
const props = defineProps<{ service: ServiceCard; projectId: string }>();
const { t } = useT();
const busy = ref(false);

/** Turn the service on and open it. */
async function enable() {
    busy.value = true;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', `/projects/${props.projectId}/services/${props.service.key}`);
        flash(result.message);
        await refreshShell();
        await navigateTo(local(result.redirect));
    } catch {
        busy.value = false;
    }
}
</script>

<template>
    <div :class="['flex h-full flex-col rounded-2xl border p-5', service.enabled ? 'border-line bg-surface shadow-card' : 'border-dashed border-line']">
        <div class="flex items-start gap-3">
            <span :class="['grid size-10 shrink-0 place-items-center rounded-xl text-white', service.enabled ? serviceStyle(service.key).tone : 'bg-black/20 dark:bg-white/20']" aria-hidden="true"><AcmeIcon :name="serviceStyle(service.key).icon" :size="18" /></span>
            <div class="min-w-0 flex-1">
                <h3 class="flex items-center gap-2 font-semibold text-ink">{{ service.name }}<AcmeBadge v-if="service.enabled" tone="green" dot>{{ t('On') }}</AcmeBadge></h3>
                <p class="mt-0.5 text-sm text-muted">{{ service.tagline }}</p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-1.5">
            <template v-if="service.enabled && service.canUse">
                <NuxtLink v-for="section in service.sections.slice(0, 4)" :key="section.url" :to="section.url" class="rounded-full border border-line px-2.5 py-1 text-xs text-ink hover:bg-black/[.04] dark:hover:bg-white/[.06]">{{ section.label }}</NuxtLink>
                <NuxtLink v-if="service.sections.length === 0" :to="service.url" class="rounded-full border border-line px-2.5 py-1 text-xs text-ink hover:bg-black/[.04] dark:hover:bg-white/[.06]">{{ t('Open :service', { service: service.name }) }}</NuxtLink>
            </template>
            <AcmeBtn v-else-if="!service.enabled && service.canManage" size="sm" variant="primary" icon="plus" :loading="busy" @click="enable">{{ t('Turn on :service', { service: service.name }) }}</AcmeBtn>
            <p v-else-if="!service.canUse" class="text-xs text-muted">{{ t('You don’t have access to :service in this account.', { service: service.name }) }}</p>
            <p v-else class="text-xs text-muted">{{ t('Someone who manages projects can turn it on.') }}</p>
        </div>
    </div>
</template>
