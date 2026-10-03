<script setup lang="ts">
/**
 * A page's sections as tabs, linked with `?tab=`, as Signal's local navigation: refreshing, bookmarks and redirects
 * after saving open the same tab. The page renders only the current tab's panel.
 */
defineProps<{ tabs: Record<string, string>; current: string; label: string }>();
</script>

<template>
    <nav class="ui-local-nav" :aria-label="label">
        <div class="ui-local-nav__scroll">
            <NuxtLink v-for="(title, key) in tabs" :key="key" :to="{ query: { tab: key } }" class="ui-local-nav__link" :aria-current="key === current ? 'page' : undefined">{{ title }}</NuxtLink>
        </div>
    </nav>
</template>
