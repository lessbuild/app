<script setup lang="ts">
/**
 * The signed-in app's footer, fixed to the bottom of the window: whether the platform is working, and quick ways to
 * search, get help, see what's new and send feedback without leaving the page.
 */
const props = defineProps<{ operational: boolean | null }>();
const { t } = useT();
const status = computed(() => (props.operational === true ? t('All systems working') : props.operational === false ? t('Some systems have problems') : t('System status')));
const kinds = computed(() => [
    { value: 'idea', label: t('An idea') },
    { value: 'problem', label: t('Something’s wrong') },
    { value: 'question', label: t('A question') },
    { value: 'praise', label: t('Something I like') },
]);
const kind = ref('idea');
const page = ref('');
onMounted(() => (page.value = window.location.href));

/** Open the command palette. */
function search() {
    window.dispatchEvent(new Event('buildpusher:command-palette'));
}
</script>

<template>
    <footer class="app-footer fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur" :aria-label="t('Footer')">
        <div class="ui-layout-gutter mx-auto flex h-10 w-full max-w-content items-center justify-between gap-4 text-xs text-muted">
            <NuxtLink to="/status" class="inline-flex min-w-0 items-center gap-2 font-semibold hover:text-ink">
                <span :class="['size-2 shrink-0 rounded-full', operational === true ? 'bg-success' : operational === false ? 'bg-warning' : 'bg-line']" aria-hidden="true" />
                <span class="truncate">{{ status }}</span>
            </NuxtLink>
            <nav class="flex shrink-0 items-center gap-1 sm:gap-2" :aria-label="t('Footer links')">
                <button type="button" class="hidden items-center gap-1.5 rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink sm:inline-flex" @click="search">
                    {{ t('Search') }} <kbd class="ui-kbd">⌘K</kbd>
                </button>
                <NuxtLink to="/help" class="rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink">{{ t('Help') }}</NuxtLink>
                <NuxtLink to="/changelog" class="hidden rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink sm:inline">{{ t('What’s new') }}</NuxtLink>
                <NuxtLink :to="{ query: { ...$route.query, dialog: 'feedback' } }" class="rounded-control px-2 py-1 font-semibold hover:bg-surface-muted hover:text-ink">{{ t('Feedback') }}</NuxtLink>
                <span class="hidden pl-2 text-subtle md:inline">© {{ new Date().getFullYear() }} BuildPusher</span>
            </nav>
        </div>
        <FormDialog id="feedback" :title="t('Send feedback')" :description="t('Tell us what would make :app better, or what’s getting in your way. We read everything.', { app: 'BuildPusher' })" action="/api/app/feedback" :submit="t('Send feedback')">
            <input type="hidden" name="page" :value="page">
            <SelectField id="feedback-kind" v-model="kind" name="kind" :label="t('What’s it about?')" :options="kinds" required />
            <TextareaField id="feedback-message" name="message" :label="t('Your feedback')" rows="5" maxlength="5000" required autofocus />
        </FormDialog>
    </footer>
</template>
