<script setup lang="ts">
import type { NavLink } from '~/types/shell';

/**
 * A row of topbar links, marking the current one: the tab of the service the page belongs to, else the link with the
 * longest path the page's path starts with.
 */
const props = defineProps<{ items: NavLink[]; label: string }>();
const route = useRoute();

const current = computed(() => currentNavUrl(props.items, route));
</script>

<template>
    <nav class="ui-horizontal-scroll flex min-w-0 items-center gap-1 overflow-x-auto" :aria-label="label">
        <NuxtLink v-for="item in items" :key="item.url" :to="item.url" class="topbar-nav-link" :aria-current="item.url === current ? 'page' : undefined">{{ item.label }}</NuxtLink>
    </nav>
</template>
