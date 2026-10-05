<script setup lang="ts">
import type { SharedReportPage } from '~/types/analytics';

/**
 * A report shown outside the app (a shared link, its embed, or someone's view-only link): the site's name, the period
 * picker and the report, or the password form when a shared report is protected.
 */
const props = defineProps<{ api: string; unlock?: string | null }>();
const { t } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const FILTERS = ['path', 'source', 'campaign', 'device', 'browser', 'os', 'channel', 'country', 'region', 'city', 'screen', 'term', 'content', 'group'];
const { data } = await useApi<SharedReportPage>(() => props.api, () => Object.fromEntries(['days', 'from', 'to', 'compare', ...FILTERS].map((key) => [key, text(route.query[key])])));
const period = computed(() => data.value.report?.period);
const form = reactive({ period: { days: period.value && !period.value.custom ? String(period.value.days) : '30', from: period.value?.custom ? period.value.start : '', to: period.value?.custom ? period.value.end : '', compare: period.value?.compare ?? 'previous' } });
const filters = computed(() => Object.fromEntries(Object.entries(data.value.filters ?? {}).filter((entry): entry is [string, string] => typeof entry[1] === 'string')));
const link = (next: Record<string, string>) => ({ path: route.path, query: { ...(period.value?.query ?? {}), ...next } });
const periodOnly = computed(() => Object.fromEntries(Object.entries(period.value?.query ?? {}).map(([key, value]) => [key, String(value)])));
const liveUrl = computed(() => `${props.api}${props.api.includes('?') ? '&' : '?'}${new URLSearchParams({ live: '1', ...filters.value })}`);
useHead({ title: () => (data.value.locked ? t('Shared report') : t(':site analytics', { site: data.value.site })), meta: [{ name: 'robots', content: 'noindex' }] });

/** Show the report for the chosen period, keeping the filters. */
function apply() {
    const periodQuery = form.period.from && form.period.to ? { from: form.period.from, to: form.period.to } : { days: form.period.days ?? '30' };
    navigateTo({ query: { ...periodQuery, compare: form.period.compare === 'previous' ? undefined : form.period.compare ?? undefined, ...filters.value } });
}
</script>

<template>
    <main id="main-content" tabindex="-1" :class="['mx-auto grid max-w-7xl gap-6 px-4', data.embed ? 'py-4' : 'py-10 sm:px-8']">
        <template v-if="data.locked">
            <section class="mx-auto grid w-full max-w-md gap-4 ui-card p-6">
                <h1 class="text-xl font-semibold text-ink">{{ data.site }}</h1>
                <p v-if="data.embed" class="text-sm text-muted">{{ t('This report is password-protected, so it can’t be embedded. Share it without a password to embed it.') }}</p>
                <template v-else>
                    <p class="text-sm text-muted">{{ t('This report is protected. Enter the password you were given.') }}</p>
                    <ApiForm v-if="unlock" :action="unlock" class="grid gap-4">
                        <PasswordField id="report-password" name="password" :label="t('Password')" required />
                        <div><SubmitButton>{{ t('Open the report') }}</SubmitButton></div>
                    </ApiForm>
                </template>
            </section>
        </template>
        <template v-else-if="data.report">
            <header v-if="!data.embed" class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ t('Shared report') }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-ink">{{ data.site }}</h1>
                </div>
            </header>
            <form class="ui-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end" @submit.prevent="apply">
                <PeriodFields v-model="form.period" :today="data.today ?? null" />
                <div><AcmeBtn type="submit" variant="primary">{{ t('Apply') }}</AcmeBtn></div>
                <div v-if="Object.keys(filters).length > 0" class="sm:col-span-2 lg:col-span-5"><AcmeBtn variant="ghost" size="sm" :to="{ query: periodOnly }">{{ t('Clear filters') }}</AcmeBtn></div>
            </form>
            <AnalyticsReport :report="data.report" :filters="data.filters ?? {}" :link="link" :live-url="liveUrl" />
            <p v-if="!data.embed" class="text-xs text-muted">{{ t('Cookieless analytics by :app.', { app: 'BuildPusher' }) }}</p>
        </template>
    </main>
</template>
