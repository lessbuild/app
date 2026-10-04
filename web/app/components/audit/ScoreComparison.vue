<script setup lang="ts">
import type { SiteScore } from '~/types/audit';

/**
 * How the audited site scores in each category against its competitors: the site as an accent bar and each competitor
 * as a grey dot on the same 0–100 scale. Every mark shows its value and site on hover and focus, and the same numbers
 * are in the table below, so nothing depends on colour or hovering. Positions come from the data, hence the inline
 * percentages.
 */
const props = defineProps<{ sites: SiteScore[] }>();
const { t } = useT();
const hover = ref<{ category: string; siteKey: string } | null>(null);
const site = computed(() => props.sites[0]);
const competitors = computed(() => props.sites.slice(1));
const score = (entry: SiteScore, key: string) => entry.categories.find((category) => category.key === key)?.score;
/** The marks in a category: the site first, then each competitor with a score there. */
const marks = (key: string) => props.sites.map((entry) => ({ entry, value: score(entry, key) })).filter((mark): mark is { entry: SiteScore; value: number } => mark.value !== undefined);
const active = (key: string) => (hover.value?.category === key ? marks(key).find((mark) => mark.entry.key === hover.value?.siteKey) : undefined);
const show = (category: string, entry: SiteScore) => (hover.value = { category, siteKey: entry.key });
</script>

<template>
    <figure v-if="site" class="grid gap-4">
        <figcaption class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-extrabold text-ink">{{ t('Scores by category') }}</span>
            <span v-if="competitors.length > 0" class="flex flex-wrap items-center gap-4 text-xs font-semibold text-muted" aria-hidden="true">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-4 rounded-sm bg-[var(--audit-chart-site)]" />{{ site.name }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-[var(--audit-chart-other)]" />{{ t('Competitors') }}</span>
            </span>
        </figcaption>

        <div class="grid gap-3" role="list" :aria-label="t('Scores by category')">
            <div v-for="category in site.categories" :key="category.key" role="listitem" class="grid grid-cols-[7.5rem_1fr_2.25rem] items-center gap-3 sm:grid-cols-[9rem_1fr_2.25rem]">
                <span class="truncate text-sm font-semibold text-muted">{{ category.label }}</span>
                <div class="relative h-7">
                    <span v-for="tick in [0, 50, 100]" :key="tick" class="absolute inset-y-0 w-px bg-line" :style="{ left: `${tick}%` }" aria-hidden="true" />
                    <span class="absolute left-0 top-1/2 h-2.5 -translate-y-1/2 rounded-r-[4px] bg-[var(--audit-chart-site)]" :style="{ width: `${category.score}%` }" aria-hidden="true" />
                    <button
                        type="button"
                        class="absolute left-0 top-1/2 h-6 min-w-6 -translate-y-1/2 cursor-default rounded-sm outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        :style="{ width: `${category.score}%` }"
                        :aria-label="`${category.label}: ${site.name} ${category.score}`"
                        @pointerenter="show(category.key, site)"
                        @pointerleave="hover = null"
                        @focus="show(category.key, site)"
                        @blur="hover = null"
                    />
                    <button
                        v-for="mark in marks(category.key).slice(1)"
                        :key="mark.entry.key"
                        type="button"
                        class="absolute top-1/2 grid size-6 -translate-x-1/2 -translate-y-1/2 cursor-default place-items-center rounded-full outline-none focus-visible:outline-2 focus-visible:outline-focus"
                        :style="{ left: `${mark.value}%` }"
                        :aria-label="`${category.label}: ${mark.entry.name} ${mark.value}`"
                        @pointerenter="show(category.key, mark.entry)"
                        @pointerleave="hover = null"
                        @focus="show(category.key, mark.entry)"
                        @blur="hover = null"
                    >
                        <span :class="['block rounded-full bg-[var(--audit-chart-other)] ring-2 ring-surface', active(category.key)?.entry.key === mark.entry.key ? 'size-3' : 'size-2.5']" />
                    </button>
                    <span
                        v-if="active(category.key)"
                        role="tooltip"
                        class="pointer-events-none absolute bottom-full z-10 mb-1 flex -translate-x-1/2 items-center gap-2 whitespace-nowrap rounded-control border border-line bg-surface px-2.5 py-1.5 text-xs shadow-panel"
                        :style="{ left: `${Math.max(8, Math.min(active(category.key)!.value, 92))}%` }"
                    >
                        <span :class="active(category.key)!.entry.key === site.key ? 'h-0.5 w-3 bg-[var(--audit-chart-site)]' : 'size-2 rounded-full bg-[var(--audit-chart-other)]'" aria-hidden="true" />
                        <strong class="font-extrabold tabular-nums text-ink">{{ active(category.key)!.value }}</strong>
                        <span class="text-muted">{{ active(category.key)!.entry.name }}</span>
                    </span>
                </div>
                <span class="text-right text-sm font-extrabold tabular-nums text-ink" aria-hidden="true">{{ category.score }}</span>
            </div>
            <div class="grid grid-cols-[7.5rem_1fr_2.25rem] gap-3 sm:grid-cols-[9rem_1fr_2.25rem]" aria-hidden="true">
                <span />
                <div class="relative h-4 text-[11px] tabular-nums text-subtle">
                    <span class="absolute left-0">0</span><span class="absolute left-1/2 -translate-x-1/2">50</span><span class="absolute right-0">100</span>
                </div>
                <span />
            </div>
        </div>

        <Disclosure :title="t('Show the scores as a table')">
            <DataTable :caption="t('Scores by category')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Category') }}</th><th v-for="entry in sites" :key="entry.key" scope="col" class="text-right">{{ entry.name }}</th></tr>
                </template>
                <tr v-for="category in site.categories" :key="category.key">
                    <th scope="row" class="font-semibold">{{ category.label }}</th>
                    <td v-for="entry in sites" :key="entry.key" class="text-right tabular-nums">{{ score(entry, category.key) ?? '—' }}</td>
                </tr>
                <tr>
                    <th scope="row" class="font-extrabold">{{ t('Overall') }}</th>
                    <td v-for="entry in sites" :key="entry.key" class="text-right font-extrabold tabular-nums">{{ entry.score }}</td>
                </tr>
            </DataTable>
        </Disclosure>
    </figure>
</template>
