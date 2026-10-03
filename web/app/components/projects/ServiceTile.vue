<script setup lang="ts">
import type { ServiceCard } from '~/types/projects';

/** One service on the project overview: open it, turn it on, or say who can. */
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
    <div class="ui-card flex h-full flex-col gap-4 p-5">
        <div class="flex items-start gap-3">
            <span :class="[`product-icon-${service.key === 'monitoring' ? 'monitor' : service.key}`, 'grid h-10 w-10 shrink-0 place-items-center rounded-card']" aria-hidden="true">
                <Icon :name="service.icon" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h3 class="flex flex-wrap items-center gap-2 font-extrabold text-ink">
                    {{ service.name }}
                    <Badge v-if="service.enabled" tone="success">{{ t('On') }}</Badge>
                </h3>
                <p class="mt-1 text-sm text-muted">{{ service.tagline }}</p>
            </div>
        </div>
        <div class="mt-auto">
            <UiButton v-if="service.enabled && service.canUse" :to="service.url" size="sm">{{ t('Open :service', { service: service.name }) }}</UiButton>
            <UiButton v-else-if="!service.enabled && service.canManage" variant="primary" size="sm" :disabled="busy" :aria-busy="busy || undefined" @click="enable">
                {{ busy ? t('Working…') : t('Turn on :service', { service: service.name }) }}
            </UiButton>
            <p v-else-if="!service.canUse" class="text-xs text-muted">{{ t('You don’t have access to :service in this account.', { service: service.name }) }}</p>
            <p v-else class="text-xs text-muted">{{ t('Someone who manages projects can turn it on.') }}</p>
        </div>
    </div>
</template>
