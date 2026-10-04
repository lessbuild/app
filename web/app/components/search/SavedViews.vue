<script setup lang="ts">
/**
 * The person's saved views of a filtered page, as links, and saving the current filters under a name. The `keys` are
 * the filters the page keeps (the API drops any others).
 */
const props = defineProps<{ page: string; keys: string[]; project?: string }>();
const { t } = useT();
const route = useRoute();
type View = { id: number; name: string; url: string | null };
const views = ref<View[]>([]);
const name = ref('');
const error = ref<string | null>(null);
const saving = ref(false);
const current = computed(() => Object.fromEntries(props.keys.flatMap((key) => (typeof route.query[key] === 'string' && route.query[key] ? [[key, route.query[key]]] : []))));

async function load() {
    const query = new URLSearchParams({ page: props.page, ...(props.project ? { project: props.project } : {}) });
    views.value = (await send<{ views: View[] }>('GET', `/saved-views?${query}`).catch(() => ({ views: [] }))).views;
}
onMounted(load);

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const result = await send<{ message: string }>('POST', '/saved-views', { saved_view_page: props.page, saved_view_name: name.value, parameters: props.project ? { project: props.project } : {}, query: current.value });
        flash(result.message);
        name.value = '';
        await navigateTo({ query: { ...route.query, dialog: undefined } });
        await load();
    } catch (problem) {
        error.value = problem instanceof ValidationError ? (problem.first('saved_view_name') ?? problem.first('saved_view_page') ?? problem.message) : t('Something went wrong. Try again.');
    }
    saving.value = false;
}

async function remove(view: View) {
    await send('DELETE', `/saved-views/${view.id}`).catch(() => null);
    await load();
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" :aria-label="t('Saved views')" role="group">
        <span v-for="view in views" :key="view.id" class="ui-chip inline-flex items-center gap-1">
            <NuxtLink v-if="view.url" :to="local(view.url)" class="font-semibold text-ink hover:underline">{{ view.name }}</NuxtLink>
            <span v-else>{{ view.name }}</span>
            <button type="button" class="ui-icon-btn ui-icon-btn-sm -my-1" :aria-label="t('Delete :name', { name: view.name })" @click="remove(view)"><Icon name="close" class="h-3.5 w-3.5" /></button>
        </span>
        <UiButton v-if="Object.keys(current).length > 0" variant="quiet" size="sm" :to="{ query: { ...route.query, dialog: `save-view-${page}` } as Record<string, string | undefined> }">
            <Icon name="bookmark-solid" class="h-4 w-4" />{{ t('Save this view') }}
        </UiButton>
        <UiDialog :id="`save-view-${page}`" :title="t('Save this view')">
            <form class="grid gap-4" @submit.prevent="save">
                <UiField :id="`save-view-name-${page}`" :label="t('Name')" :error="error ?? undefined">
                    <input :id="`save-view-name-${page}`" v-model="name" class="ui-input" maxlength="60" required autofocus>
                </UiField>
                <div class="flex justify-end"><UiButton type="submit" variant="primary" :disabled="saving">{{ saving ? t('Working…') : t('Save') }}</UiButton></div>
            </form>
        </UiDialog>
    </div>
</template>
