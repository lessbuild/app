<script setup lang="ts">
import type { PreviewsPage } from '~/types/deploy';
import type { Tone } from '~/types/ui';

/**
 * Previews: a website per pull request (or per branch, opened here) with its own environment and deploys, the
 * secrets approved for its code, and the ones closed lately with how their cleanup went.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<PreviewsPage>(() => `/projects/${route.params.project}/deploy/previews`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/previews`);
const statuses = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    ready: { tone: 'success', label: t('Ready') },
    failed: { tone: 'danger', label: t('Failed') },
    deploying: { tone: 'info', label: t('Deploying') },
    provisioning: { tone: 'info', label: t('Setting up') },
    closed: { tone: 'neutral', label: t('Closed') },
}));
const days = computed(() => [1, 3, 7, 14, 30].map((count) => ({ value: String(count), label: tc(':count day|:count days', count, { count }) })));
const closesAfter = ref<string | null>('7');
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Previews')"
            :description="t('Each pull request into a repository’s branch, and any branch you open one for, gets its own website, environment and deploys, removed when the pull request closes or the preview expires.')"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <StatCard :label="t('Open previews')" :value="data.limit === null ? String(data.used) : t(':used of :limit', { used: data.used, limit: data.limit })" :description="t('Across the account. Each also uses a website.')" />
            <StatCard
                :label="t('Repositories with previews')"
                :value="data.repositories.length === 0 ? t('None') : data.repositories.map((repository) => repository.label).join(', ')"
                :description="data.allowed ? t('Turn previews on in a repository’s settings; its push webhook must be on too.') : t('Previews come with the Pro Deploy plan and above.')"
            />
        </div>

        <SettingsSection
            v-if="data.allowed && data.repositories.length > 0"
            id="branch-preview"
            :title="t('Preview a branch')"
            :description="t('Spin up a preview of any branch, not only a pull request. It deploys the branch’s latest commit and closes on the date you choose; open it again to deploy newer commits or move the date.')"
        >
            <ApiForm :action="`${base}/branch`" class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem_auto] sm:items-end sm:p-6">
                <SelectField name="repository_id" :label="t('Repository')" :options="data.repositories" />
                <InputField name="branch" :label="t('Branch')" maxlength="200" required placeholder="feature/new-checkout" />
                <SelectField v-model="closesAfter" name="days" :label="t('Closes after')" :options="days" />
                <SubmitButton variant="secondary">{{ t('Open preview') }}</SubmitButton>
            </ApiForm>
        </SettingsSection>

        <EmptyState v-if="data.open.length === 0 && data.closed.length === 0" icon="cloud-upload" :title="t('No previews yet')" :description="t('Open a pull request into a repository that has previews on.')" />

        <section v-for="preview in data.open" :key="preview.id" class="ui-card grid gap-4 p-5 sm:p-6" :aria-labelledby="`preview-${preview.id}`">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h2 :id="`preview-${preview.id}`" class="font-extrabold text-ink">{{ preview.repository }} · {{ preview.label }}</h2>
                    <p v-if="preview.pullRequest !== null && preview.title" class="text-sm text-muted">{{ preview.title }}</p>
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <Badge :tone="statuses[preview.status]?.tone ?? 'neutral'">{{ statuses[preview.status]?.label ?? preview.status }}</Badge>
                        <span class="font-mono text-xs text-muted">{{ preview.branch }} · {{ preview.shortRevision ?? '—' }}</span>
                    </p>
                    <p v-if="preview.url"><a :href="`https://${preview.url}`" class="font-bold text-primary hover:underline" rel="noopener" target="_blank">{{ preview.url }}</a></p>
                    <p class="text-xs text-muted">
                        <Rich :text="preview.pullRequest !== null ? t('Closes :when unless the pull request changes.') : t('Closes :when.')">
                            <template #when><RelativeTime :at="preview.expiresAt" /></template>
                        </Rich>
                        <template v-if="preview.deploysRepositoryId"> · <NuxtLink :to="`/projects/${project.id}/deploy/repositories/${preview.deploysRepositoryId}`" class="text-primary hover:underline">{{ t('Deploys') }}</NuxtLink></template>
                        <template v-if="preview.websiteError"> · <span class="text-danger">{{ preview.websiteError }}</span></template>
                    </p>
                </div>
                <ApiForm v-if="preview.canOperate" :action="`${base}/${preview.id}/close`" :confirm="t('Close this preview? Its website and environment are removed.')">
                    <SubmitButton variant="secondary" size="sm">{{ t('Close preview') }}</SubmitButton>
                </ApiForm>
            </div>

            <div class="space-y-2 border-t border-line pt-4 text-sm">
                <p class="font-bold">{{ t('Secrets') }}</p>
                <p v-if="preview.approvedSecrets" class="text-muted">
                    {{ tc(':count secret approved for this revision by :name.|:count secrets approved for this revision by :name.', preview.approvedSecrets.count, { count: preview.approvedSecrets.count, name: preview.approvedSecrets.approver ?? t('someone') }) }}
                </p>
                <p v-else class="text-muted">{{ t('None. The preview has its own key, URL and database, and the source environment’s non-secret variables.') }}</p>
                <ApiForm v-if="preview.approvable.length > 0" :action="`${base}/${preview.id}/secrets`" class="space-y-2">
                    <input type="hidden" name="revision" :value="preview.revision ?? ''">
                    <p class="text-xs text-muted">{{ t('Review revision :revision first: approved secrets reach the pull request’s code. A new revision needs a new approval.', { revision: preview.shortRevision ?? '—' }) }}</p>
                    <fieldset class="flex flex-wrap gap-x-4">
                        <legend class="sr-only">{{ t('Secrets') }}</legend>
                        <CheckboxField v-for="key in preview.approvable" :id="`secret-${preview.id}-${key}`" :key="key" name="secret_keys[]" error-key="secret_keys" :value="key" :label="key" />
                    </fieldset>
                    <SubmitButton variant="secondary" size="sm">{{ t('Approve for this revision') }}</SubmitButton>
                </ApiForm>
            </div>
        </section>

        <DataTable v-if="data.closed.length > 0" :caption="t('Recently closed')">
            <template #head>
                <tr><th scope="col">{{ t('Pull request') }}</th><th scope="col">{{ t('Closed') }}</th><th scope="col">{{ t('Cleanup') }}</th></tr>
            </template>
            <tr v-for="preview in data.closed" :key="preview.id">
                <td>{{ preview.repository }} · {{ preview.label }} <span v-if="preview.pullRequest !== null" class="text-muted">{{ preview.title }}</span></td>
                <td><RelativeTime v-if="preview.closedAt" :at="preview.closedAt" /></td>
                <td>
                    <Badge v-if="preview.cleanupStatus === 'succeeded'" tone="success">{{ t('Removed') }}</Badge>
                    <template v-else-if="preview.cleanupStatus === 'failed'">
                        <Badge tone="danger">{{ t('Failed') }}</Badge>
                        <span class="block text-xs text-muted">{{ preview.cleanupError }}</span>
                        <ApiForm v-if="preview.canOperate" :action="`${base}/${preview.id}/cleanup`" class="mt-1"><SubmitButton variant="quiet" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                    </template>
                    <span v-else-if="preview.cleanupStatus === null" class="text-muted">{{ t('Waiting for a deploy to finish') }}</span>
                    <Badge v-else tone="info">{{ t('Removing') }}</Badge>
                </td>
            </tr>
        </DataTable>
    </div>
</template>
