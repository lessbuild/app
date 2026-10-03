<script setup lang="ts">
import type { ProjectOverview, ServiceOption } from '~/types/projects';

/**
 * A service inside a project. Once it's on, its own pages take over (the API says where); until then, what it does
 * and the button to turn it on.
 */
definePageMeta({ layout: 'app' });
const { t } = useT();
const route = useRoute();
type ServicePage = { redirect: string } | { overview: ProjectOverview; service: ServiceOption; enabled: boolean; canManage: boolean };
const { data } = await useApi<ServicePage>(() => `/projects/${route.params.project}/services/${route.params.service}`);
if ('redirect' in data.value) {
    await navigateTo(local(data.value.redirect), { replace: true });
}
const page = computed(() => ('redirect' in data.value ? null : data.value));
const busy = ref(false);

/** Turn the service on and open its pages. */
async function enable() {
    if (!page.value) {
        return;
    }
    busy.value = true;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', `/projects/${page.value.overview.project.id}/services/${page.value.service.key}`);
        flash(result.message);
        await refreshPage();
        busy.value = false;
    } catch {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="page" class="space-y-6">
        <ProjectHeader :overview="page.overview" :title="page.service.name" :description="page.service.tagline" />
        <section class="ui-panel grid gap-5 p-6 text-center sm:p-10" aria-labelledby="service-heading">
            <span :class="[`product-icon-${page.service.key === 'monitoring' ? 'monitor' : page.service.key}`, 'mx-auto grid size-14 place-items-center rounded-2xl']" aria-hidden="true">
                <Icon :name="page.service.icon" class="size-7" />
            </span>
            <h2 id="service-heading" class="text-2xl font-extrabold text-ink">{{ t(':service isn’t on for this project', { service: page.service.name }) }}</h2>
            <p class="mx-auto max-w-xl text-muted">{{ page.service.tagline }}</p>
            <div class="flex justify-center">
                <UiButton v-if="page.canManage" variant="primary" size="lg" :disabled="busy" :aria-busy="busy || undefined" @click="enable">
                    {{ busy ? t('Working…') : t('Turn on :service', { service: page.service.name }) }}
                </UiButton>
                <p v-else class="text-sm text-muted">{{ t('Someone who manages projects can turn it on.') }}</p>
            </div>
        </section>
    </div>
</template>
