<script setup lang="ts">
/** Previous and next links for a numbered list, kept in `?page=` alongside the page's other filters. */
const props = defineProps<{ page: number; lastPage: number }>();
const { t } = useT();
const route = useRoute();
const to = (page: number) => ({ query: { ...route.query, page: page > 1 ? String(page) : undefined } });
const first = computed(() => props.page <= 1);
const last = computed(() => props.page >= props.lastPage);
</script>

<template>
    <nav v-if="lastPage > 1" class="flex items-center justify-between gap-3 text-sm" :aria-label="t('Pages')">
        <span class="text-muted">{{ t('Page :page of :pages', { page, pages: lastPage }) }}</span>
        <span class="flex gap-2">
            <UiButton :to="to(page - 1)" size="sm" rel="prev" :class="first && 'pointer-events-none opacity-50'" :aria-disabled="first || undefined" :tabindex="first ? -1 : undefined">{{ t('Previous') }}</UiButton>
            <UiButton :to="to(page + 1)" size="sm" rel="next" :class="last && 'pointer-events-none opacity-50'" :aria-disabled="last || undefined" :tabindex="last ? -1 : undefined">{{ t('Next') }}</UiButton>
        </span>
    </nav>
</template>
