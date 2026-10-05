<script setup lang="ts">
import type { PreviewsPage } from '~/types/deploy';

/**
 * Previews (the Acme theme's previews page): a website per pull request (or per branch, opened from a dialog) with its
 * own environment and deploys, the secrets approved for its code, and the ones closed lately with how their cleanup
 * went.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<PreviewsPage>(() => `/projects/${route.params.project}/deploy/previews`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/previews`);
const statuses = computed<Record<string, { tone: 'green' | 'red' | 'blue' | 'gray'; label: string }>>(() => ({
    ready: { tone: 'green', label: t('Ready') },
    failed: { tone: 'red', label: t('Failed') },
    deploying: { tone: 'blue', label: t('Deploying') },
    provisioning: { tone: 'blue', label: t('Setting up') },
    closed: { tone: 'gray', label: t('Closed') },
}));
const days = computed(() => [1, 3, 7, 14, 30].map((count) => ({ value: String(count), label: tc(':count day|:count days', count, { count }) })));
const closesAfter = ref<string | null>('7');
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Previews')" :description="t('Each pull request into a repository’s branch, and any branch you open one for, gets its own website, environment and deploys, removed when the pull request closes or the preview expires.')">
            <template v-if="data.allowed && data.repositories.length > 0" #actions>
                <AcmeBtn variant="primary" icon="branch" :to="{ query: { dialog: 'preview-branch' } }">{{ t('Preview a branch') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center gap-4 rounded-2xl border border-line bg-surface p-4 shadow-card">
                <span class="text-sm"><span class="font-semibold text-ink">{{ data.limit === null ? data.used : t(':used of :limit', { used: data.used, limit: data.limit }) }}</span> <span class="text-muted">{{ t('open previews across the account') }}</span></span>
                <AcmeProgress v-if="data.limit" :value="Math.min(100, (data.used / data.limit) * 100)" :label="t('Previews used')" size="sm" class="min-w-32 flex-1" />
                <span class="text-xs text-muted">{{ data.allowed ? t('Each also uses a website. Turn previews on in a repository’s settings; its push webhook must be on too.') : t('Previews come with the Pro Deploy plan and above.') }}</span>
            </div>

            <AcmeEmptyCard v-if="data.open.length === 0" icon="eye" :title="t('No previews yet')" :description="t('Open a pull request into a repository that has previews on.')" />
            <ul v-else class="grid gap-4 lg:grid-cols-2">
                <li v-for="preview in data.open" :key="preview.id" class="flex flex-col rounded-2xl border border-line bg-surface p-5 shadow-card">
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-black/[.04] font-mono text-xs font-semibold text-ink dark:bg-white/[.06]" aria-hidden="true">{{ preview.pullRequest !== null ? `#${preview.pullRequest}` : '⎇' }}</span>
                        <span class="min-w-0 flex-1"><span class="block font-semibold text-ink">{{ preview.title || preview.label }}</span><span class="font-mono text-xs text-muted">{{ preview.repository }} · {{ preview.branch }} · {{ preview.shortRevision ?? '—' }}</span></span>
                        <AcmeBadge :tone="statuses[preview.status]?.tone ?? 'gray'" dot>{{ statuses[preview.status]?.label ?? preview.status }}</AcmeBadge>
                    </div>
                    <a v-if="preview.url" :href="`https://${preview.url}`" target="_blank" rel="noopener" class="mt-4 flex items-center gap-2 truncate rounded-lg bg-black/[.03] px-3 py-2 font-mono text-xs text-ink hover:underline dark:bg-white/[.04]"><AcmeIcon name="external" :size="13" />{{ preview.url }}</a>
                    <p v-if="preview.websiteError" class="mt-3 text-sm text-rose-600">{{ preview.websiteError }}</p>
                    <ApiForm v-if="preview.approvable.length > 0" :action="`${base}/${preview.id}/secrets`" class="mt-3 !grid gap-2 rounded-lg border border-amber-500/30 bg-amber-500/[.06] p-3 text-sm">
                        <input type="hidden" name="revision" :value="preview.revision ?? ''">
                        <p class="text-ink">{{ t('Review revision :revision first: approved secrets reach the pull request’s code. A new revision needs a new approval.', { revision: preview.shortRevision ?? '—' }) }}</p>
                        <fieldset class="flex flex-wrap gap-x-4">
                            <legend class="sr-only">{{ t('Secrets') }}</legend>
                            <CheckboxField v-for="key in preview.approvable" :id="`secret-${preview.id}-${key}`" :key="key" name="secret_keys[]" error-key="secret_keys" :value="key" :label="key" />
                        </fieldset>
                        <div><SubmitButton variant="secondary" size="sm">{{ t('Approve secrets for this revision') }}</SubmitButton></div>
                    </ApiForm>
                    <p v-else class="mt-3 text-xs text-muted">
                        {{ t('Secrets') }}:
                        <template v-if="preview.approvedSecrets">{{ tc(':count secret approved for this revision by :name.|:count secrets approved for this revision by :name.', preview.approvedSecrets.count, { count: preview.approvedSecrets.count, name: preview.approvedSecrets.approver ?? t('someone') }) }}</template>
                        <template v-else>{{ t('None. The preview has its own key, URL and database, and the source environment’s non-secret variables.') }}</template>
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3 text-xs text-muted">
                        <AcmeAvatar v-if="preview.author" :name="preview.author" size="xs" />
                        <span>
                            <template v-if="preview.author">{{ preview.author }} · </template>
                            <NuxtLink v-if="preview.deploysRepositoryId" :to="`/projects/${project.id}/deploy/repositories/${preview.deploysRepositoryId}`" class="hover:text-ink hover:underline">{{ tc(':count deploy|:count deploys', preview.deploys) }}</NuxtLink>
                            · <Rich :text="t('closes :when')"><template #when><RelativeTime :at="preview.expiresAt" /></template></Rich>
                        </span>
                        <ApiForm v-if="preview.canOperate" :action="`${base}/${preview.id}/close`" :confirm="t('Close this preview? Its website and environment are removed.')" class="ml-auto !block">
                            <button type="submit" class="rounded-lg px-2 py-1 text-xs font-medium text-ink hover:bg-black/[.05] dark:hover:bg-white/[.08]">{{ t('Close') }}</button>
                        </ApiForm>
                    </div>
                </li>
            </ul>

            <AcmeCard v-if="data.closed.length > 0" :title="t('Recently closed')" :padded="false">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                        <caption class="sr-only">{{ t('Recently closed') }}</caption>
                        <thead><tr class="border-b border-line text-left text-xs text-muted"><th scope="col" class="px-5 py-2.5 font-medium">{{ t('Pull request') }}</th><th scope="col" class="px-5 py-2.5 font-medium">{{ t('Closed') }}</th><th scope="col" class="px-5 py-2.5 font-medium">{{ t('Cleanup') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="preview in data.closed" :key="preview.id">
                                <td class="px-5 py-3 text-ink"><span class="font-mono text-xs text-muted">{{ preview.pullRequest !== null ? `#${preview.pullRequest}` : preview.label }}</span> {{ preview.repository }}<template v-if="preview.pullRequest !== null && preview.title"> · {{ preview.title }}</template></td>
                                <td class="px-5 py-3 text-muted"><RelativeTime v-if="preview.closedAt" :at="preview.closedAt" /></td>
                                <td class="px-5 py-3">
                                    <AcmeBadge v-if="preview.cleanupStatus === 'succeeded'" tone="green">{{ t('Removed') }}</AcmeBadge>
                                    <template v-else-if="preview.cleanupStatus === 'failed'">
                                        <AcmeBadge tone="red">{{ t('Failed') }}</AcmeBadge>
                                        <span class="block text-xs text-muted">{{ preview.cleanupError }}</span>
                                        <ApiForm v-if="preview.canOperate" :action="`${base}/${preview.id}/cleanup`" class="mt-1 !block"><SubmitButton variant="quiet" size="sm">{{ t('Retry') }}</SubmitButton></ApiForm>
                                    </template>
                                    <span v-else-if="preview.cleanupStatus === null" class="text-muted">{{ t('Waiting for a deploy to finish') }}</span>
                                    <AcmeBadge v-else tone="blue">{{ t('Removing') }}</AcmeBadge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AcmeCard>
        </div>

        <FormDialog v-if="data.allowed && data.repositories.length > 0" id="preview-branch" :title="t('Preview a branch')" :description="t('Spin up a preview of any branch, not only a pull request. It deploys the branch’s latest commit and closes on the date you choose; open it again to deploy newer commits or move the date.')" :action="`${base}/branch`" :submit="t('Open preview')">
            <SelectField id="preview-repository" name="repository_id" :label="t('Repository')" :options="data.repositories" />
            <InputField id="preview-branch-name" name="branch" :label="t('Branch')" maxlength="200" required placeholder="feature/new-checkout" />
            <SelectField id="preview-days" v-model="closesAfter" name="days" :label="t('Closes after')" :options="days" />
        </FormDialog>
    </div>
</template>
