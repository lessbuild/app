<script setup lang="ts">
/** A status page's uptime for one month: overall and per component, its incidents, and the other months. */
type MonthPage = {
    page: { slug: string; name: string; url: string };
    month: string;
    label: string;
    uptime: number | null;
    downtimeMinutes: number;
    components: Array<{ name: string; group: string | null; uptime: number | null; checks: number }>;
    incidents: Array<{ title: string; component: string; openedAt: string; resolvedAt: string | null; minutes: number }>;
    months: Array<{ value: string; label: string }>;
};
const { t, tc, number, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<MonthPage>(() => `/status/${route.params.slug}/uptime/${route.params.month}`);
useHead({
    title: () => t(':page uptime in :month', { page: data.value.page.name, month: data.value.label }),
    meta: [{ name: 'description', content: () => t('Uptime and incidents for :month.', { month: data.value.label }) }, { name: 'robots', content: 'index, follow' }],
});
</script>

<template>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-4xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow"><a :href="data.page.url" class="hover:underline">{{ data.page.name }}</a></p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ t('Uptime in :month', { month: data.label }) }}</h1>
            <p class="mt-3 text-sm text-muted">
                {{ data.uptime === null ? t('Not enough data yet.') : t(':uptime% overall', { uptime: data.uptime.toFixed(3) }) }}
                · {{ tc(':count incident|:count incidents', data.incidents.length, { count: data.incidents.length }) }}
                · {{ t(':minutes minutes of downtime', { minutes: number(data.downtimeMinutes) }) }}
            </p>
        </header>

        <DataTable :caption="t('Uptime by component')">
            <template #head><tr><th scope="col">{{ t('Component') }}</th><th scope="col" class="text-right">{{ t('Uptime') }}</th><th scope="col" class="text-right">{{ t('Checks') }}</th></tr></template>
            <tr v-for="row in data.components" :key="`${row.group}-${row.name}`">
                <td class="font-bold text-ink">{{ row.name }}<span v-if="row.group" class="text-xs font-normal text-muted"> · {{ row.group }}</span></td>
                <td class="text-right tabular-nums">{{ row.uptime === null ? '—' : `${row.uptime.toFixed(3)}%` }}</td>
                <td class="text-right tabular-nums">{{ number(row.checks) }}</td>
            </tr>
        </DataTable>

        <section v-if="data.incidents.length > 0" class="ui-card overflow-hidden" aria-labelledby="month-incidents">
            <div class="border-b border-line px-5 py-4 sm:px-6"><h2 id="month-incidents" class="font-extrabold text-ink">{{ t('Incidents') }}</h2></div>
            <ul class="divide-y divide-line">
                <li v-for="(incident, index) in data.incidents" :key="index" class="px-5 py-4 sm:px-6">
                    <p class="font-bold text-ink">{{ incident.title }}</p>
                    <p class="text-xs text-muted">
                        {{ incident.component }} · {{ dateTime(incident.openedAt) }}<template v-if="incident.resolvedAt"> – {{ dateTime(incident.resolvedAt) }}</template>
                        · {{ tc(':count minute|:count minutes', incident.minutes, { count: number(incident.minutes) }) }}
                    </p>
                </li>
            </ul>
        </section>

        <nav :aria-label="t('Other months')" class="flex flex-wrap gap-2">
            <UiButton v-for="month in data.months" :key="month.value" :to="`/status/${data.page.slug}/uptime/${month.value}`" size="sm" :variant="month.value === data.month ? 'soft' : 'quiet'" :aria-current="month.value === data.month ? 'page' : undefined">{{ month.label }}</UiButton>
        </nav>
    </main>
</template>
