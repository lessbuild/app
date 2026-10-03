<script setup lang="ts">
/**
 * Signal's page header: breadcrumbs, the title and description, the page's main actions (the `actions` slot) and
 * details under the description (the `metadata` slot). Also sets the document title.
 */
const props = withDefaults(defineProps<{
    title: string;
    description?: string | null;
    eyebrow?: string | null;
    icon?: string;
    breadcrumbs?: Array<{ label: string; to?: string }>;
}>(), { description: null, eyebrow: null, icon: undefined, breadcrumbs: () => [] });
const { t } = useT();
const crumbs = computed(() => [...props.breadcrumbs, { label: props.title, to: undefined }]);

useHead({ title: () => props.title });
</script>

<template>
    <header class="ui-page-header mb-7 flex scroll-mt-24 flex-col gap-4 border-b border-line pb-6 sm:mb-8 sm:flex-row sm:items-end sm:justify-between" data-ui-page-header>
        <div class="ui-page-header__layout min-w-0 flex-1">
            <nav v-if="breadcrumbs.length > 0" :aria-label="t('Breadcrumb')" class="mb-4 min-w-0">
                <ol class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-muted">
                    <li v-for="(crumb, index) in crumbs" :key="index" class="flex min-w-0 items-center gap-2" :aria-current="index === breadcrumbs.length ? 'page' : undefined">
                        <Icon v-if="index > 0" name="chevron-right" class="h-[13px] w-[13px] text-subtle" />
                        <NuxtLink v-if="crumb.to" class="ui-link" :to="crumb.to">{{ crumb.label }}</NuxtLink>
                        <span v-else :class="index === breadcrumbs.length ? 'truncate font-bold text-ink' : undefined">{{ crumb.label }}</span>
                    </li>
                </ol>
            </nav>
            <div class="ui-page-header__identity flex min-w-0 max-w-3xl items-start gap-4">
                <span v-if="icon" class="ui-page-header__icon mt-2 hidden h-10 w-10 shrink-0 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)] sm:grid" aria-hidden="true">
                    <Icon :name="icon" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <p v-if="eyebrow" class="ui-page-header__eyebrow ui-eyebrow flex items-center gap-2">{{ eyebrow }}</p>
                    <h1 class="ui-page-header__title mt-1 break-words text-3xl font-extrabold tracking-tight text-ink sm:text-4xl" data-page-title>{{ title }}</h1>
                    <p v-if="description" class="ui-page-header__description mt-3 max-w-2xl text-sm leading-6 text-muted sm:text-base sm:leading-7">{{ description }}</p>
                    <div v-if="$slots.metadata" class="mt-3 flex min-w-0 flex-wrap items-center gap-2"><slot name="metadata" /></div>
                </div>
            </div>
        </div>
        <div v-if="$slots.actions" class="ui-page-header__actions flex flex-wrap gap-2"><slot name="actions" /></div>
    </header>
</template>
