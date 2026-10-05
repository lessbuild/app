<script setup lang="ts">
import { PageRouteSymbol } from '#app/components/injections';
import type { Component } from 'vue';

/**
 * The drawer that shows a create or edit page over the current one (`?panel=<its address>`). The page runs as it would
 * on its own (its address, data and form) but its header becomes the drawer's heading; saving closes the drawer and
 * reloads the page underneath, unless the page's next step is somewhere else (`definePageMeta({ panel: 'follow' })`).
 */
const { t } = useT();
const route = useRoute();
const router = useRouter();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();
const heading = reactive({ title: '', description: '' as string | null | undefined });
const path = computed(() => (typeof route.query.panel === 'string' && isPanelPath(route.query.panel) ? route.query.panel : null));
const target = computed(() => (path.value ? router.resolve(path.value) : null));
const page = computed<Component | null>(() => {
    const record = target.value?.matched.at(-1);
    const component = record?.components?.default;
    if (!component) {
        return null;
    }
    return typeof component === 'function' ? defineAsyncComponent(component as () => Promise<Component>) : (component as Component);
});

// The page inside reads its own address (its parameters and query) through useRoute().
const pageRoute = new Proxy({}, { get: (_, key) => (target.value as Record<PropertyKey, unknown> | null)?.[key] }) as ReturnType<typeof useRoute>;
provide(PageRouteSymbol, pageRoute);

/** Close the drawer, staying on the page underneath. */
function close() {
    const query = { ...route.query };
    delete query.panel;
    router.replace({ query, hash: route.hash });
}

provide(pagePanelKey, {
    close,
    setHeading: (title, description) => Object.assign(heading, { title, description }),
    get follows() {
        return target.value?.meta.panel === 'follow';
    },
});

watch(path, (value) => {
    heading.title = '';
    heading.description = '';
    if (value && dialog.value && !dialog.value.open) {
        dialog.value.showModal();
    }
    if (!value && dialog.value?.open) {
        dialog.value.close();
    }
}, { flush: 'post' });
onMounted(() => path.value && dialog.value?.showModal());
</script>

<template>
    <dialog ref="dialog" class="ui-dialog ui-dialog-large ui-dialog-panel text-left" :aria-labelledby="titleId" @cancel.prevent="close" @click="(event) => event.target === dialog && close()">
        <div v-if="path && page" data-modal-panel class="flex h-full flex-col">
            <header data-modal-header class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 :id="titleId" class="text-lg font-semibold text-ink">{{ heading.title }}</h2>
                    <p v-if="heading.description" class="mt-1 text-sm text-muted">{{ heading.description }}</p>
                </div>
                <button type="button" class="ui-icon-btn" :aria-label="t('Close :title', { title: heading.title })" autofocus @click="close">
                    <Icon name="close" class="h-5 w-5" />
                </button>
            </header>
            <div data-modal-body class="@container min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                <Suspense>
                    <component :is="page" :key="path" />
                    <template #fallback><p class="text-sm text-muted" role="status">{{ t('Loading…') }}</p></template>
                </Suspense>
            </div>
        </div>
    </dialog>
</template>
