<script setup lang="ts">
/** The recipe pages: the account's library, the gallery, the person's reports, and reports on the account's recipes. */
const props = defineProps<{ reports: boolean }>();
const { t } = useT();
const route = useRoute();
const links = computed(() => [
    { to: '/account/recipes', label: t('Your recipes'), current: route.path === '/account/recipes' || /^\/account\/recipes\/\d+$/.test(route.path) },
    { to: '/recipes/gallery', label: t('Gallery'), current: route.path === '/recipes/gallery' || /^\/recipes\/gallery\/\d+$/.test(route.path) },
    { to: '/recipes/gallery/reports', label: t('Your reports'), current: route.path === '/recipes/gallery/reports' },
    ...(props.reports ? [{ to: '/account/recipes/reports', label: t('Reports on our recipes'), current: route.path === '/account/recipes/reports' }] : []),
]);
</script>

<template>
    <nav class="ui-local-nav" :aria-label="t('Recipes')">
        <div class="ui-local-nav__scroll">
            <NuxtLink v-for="link in links" :key="link.to" :to="link.to" class="ui-local-nav__link" :aria-current="link.current ? 'page' : undefined">{{ link.label }}</NuxtLink>
        </div>
    </nav>
</template>
