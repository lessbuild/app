<script setup lang="ts">
import type { DetectedApp, RepositoryPage } from '~/types/deploy';

/**
 * What's in a repository, read through its Git provider: each app with its framework and what it needs (the one this
 * repository deploys marked), and what its environment is still missing, with a button for each fix Buildpusher can
 * make. Look again reads the branch afresh.
 */
const props = defineProps<{ page: RepositoryPage; base: string }>();
const { t } = useT();
const stack = computed(() => props.page.stack);
const tone = (severity: string) => acmeTone(severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'info');

/** The things an app needs, in a few words each. */
function needs(app: DetectedApp): string[] {
    return [
        app.database ? t('database: :name', { name: app.database === 'pgsql' ? 'PostgreSQL' : app.database === 'mysql' ? 'MySQL' : 'SQLite' }) : null,
        app.queue ? t('queue worker') : null,
        app.scheduler ? t('scheduler') : null,
        app.redis ? 'Redis' : null,
    ].filter((need): need is string => need !== null);
}
</script>

<template>
    <AcmeCard id="stack" :padded="false" :title="t('What’s in this repository')" :description="t('Read from :branch through the Git provider, so deploys and suggestions fit the app.', { branch: page.repository.branch })">
        <template v-if="page.canManage" #action>
            <ApiForm :action="`${base}/inspect`"><SubmitButton variant="secondary" size="sm">{{ t('Look again') }}</SubmitButton></ApiForm>
        </template>
        <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
            <AcmeAlert v-if="stack.error" tone="warning">{{ stack.error }}</AcmeAlert>
            <p v-else-if="!stack.checkedAt" class="text-sm text-muted">{{ t('Not read yet. Look again reads it now.') }}</p>
            <p v-else-if="stack.apps.length === 0" class="text-sm text-muted">{{ t('No app found: nothing here that Buildpusher knows how to deploy.') }}</p>
            <ul v-else class="grid gap-2">
                <li v-for="app in stack.apps" :key="app.path" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <span class="font-bold text-ink">{{ app.label }}</span>
                    <span class="font-mono text-muted">{{ app.path === '' ? t('top level') : `${app.path}/` }}</span>
                    <AcmeBadge v-if="app.path === stack.deploys" tone="violet" dot>{{ t('This repository deploys it') }}</AcmeBadge>
                    <span v-if="needs(app).length > 0" class="text-muted">· {{ t('needs :things', { things: needs(app).join(', ') }) }}</span>
                    <p v-for="note in app.notes" :key="note" class="w-full text-xs text-muted">{{ note }}</p>
                </li>
            </ul>
            <ul v-if="stack.suggestions.length > 0" class="divide-y divide-line rounded-xl border border-line">
                <li v-for="suggestion in stack.suggestions" :key="suggestion.key" class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-ink"><AcmeBadge :tone="tone(suggestion.severity)" dot>{{ suggestion.severity === 'notice' ? t('Suggestion') : t('Warning') }}</AcmeBadge>{{ suggestion.title }}</p>
                        <p class="mt-1 text-sm text-muted">{{ suggestion.detail }}</p>
                    </div>
                    <ApiForm v-if="page.canManage && suggestion.fixable" :action="`${base}/suggestions`">
                        <input type="hidden" name="key" :value="suggestion.key">
                        <SubmitButton variant="secondary" size="sm">{{ suggestion.fixLabel }}</SubmitButton>
                    </ApiForm>
                </li>
            </ul>
        </div>
    </AcmeCard>
</template>
