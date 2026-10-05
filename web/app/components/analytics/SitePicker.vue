<script setup lang="ts">
import type { SiteRow } from '~/types/analytics';

/** Chooses the site on Analytics pages that show one site at a time, keeping the page's other query parameters. */
const props = defineProps<{ sites: SiteRow[]; site: SiteRow | null }>();
const { t } = useT();
const route = useRoute();
const chosen = ref(props.site ? String(props.site.id) : null);
const options = computed(() => props.sites.map((site) => ({ value: String(site.id), label: site.name })));

/** Show the chosen site, from the first page. */
function choose() {
    navigateTo({ query: { ...route.query, site: chosen.value ?? undefined, page: undefined } });
}
</script>

<template>
    <div v-if="sites.length > 1">
        <SelectField v-model="chosen" name="site" :label="t('Site')" :options="options" hide-label @change="choose" />
    </div>
</template>
