<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * The environment's production check: settings left at development defaults, queued jobs with nothing running them,
 * and servers that are too full or too small, each saying why it matters, with Fix (or a link to where to fix it by
 * hand) and Fix all. Fixes are ordinary variable and worker changes, so they apply with the next deploy.
 */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t, tc } = useT();
const issues = computed(() => props.page.productionCheck);
const fixable = computed(() => issues.value.filter(issue => issue.fixable).length);
const tone = (severity: string) => acmeTone(severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'info');
const label = (severity: string) => severity === 'critical' ? t('Critical') : severity === 'warning' ? t('Warning') : t('Suggestion');
</script>

<template>
    <AcmeCard
        v-if="issues.length > 0"
        id="production-check"
        :padded="false"
        :title="t('Production check')"
        :description="tc(':count thing to look at before this environment is ready for real traffic.|:count things to look at before this environment is ready for real traffic.', issues.length, { count: issues.length })"
    >
        <template v-if="page.canManage && fixable > 1" #action>
            <ApiForm :action="`${base}/production-check`"><SubmitButton size="sm">{{ t('Fix all') }}</SubmitButton></ApiForm>
        </template>
        <ul class="divide-y divide-line">
            <li v-for="issue in issues" :key="issue.key" class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 sm:px-6">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-ink">
                        <AcmeBadge :tone="tone(issue.severity)" dot>{{ label(issue.severity) }}</AcmeBadge>
                        {{ issue.title }}
                    </p>
                    <p class="mt-1 text-sm text-muted">{{ issue.detail }}</p>
                </div>
                <ApiForm v-if="page.canManage && issue.fixable" :action="`${base}/production-check`">
                    <input type="hidden" name="key" :value="issue.key">
                    <SubmitButton variant="secondary" size="sm">{{ issue.fixLabel }}</SubmitButton>
                </ApiForm>
                <UiButton v-else-if="issue.url" :to="issue.url" variant="secondary" size="sm">{{ t('Open') }}</UiButton>
            </li>
        </ul>
    </AcmeCard>
</template>
