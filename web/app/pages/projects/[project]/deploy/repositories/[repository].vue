<script setup lang="ts">
import type { RepositoryPage } from '~/types/deploy';

/**
 * One repository (the Acme theme's repository page): deploying it (now, a chosen version, or later), booked deploys,
 * and tabs for its deploys, push deploys through a webhook, and its settings (build cache, pull-request previews,
 * build settings, removing it).
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, number, dateTime } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<RepositoryPage>(() => `/projects/${route.params.project}/deploy/repositories/${route.params.repository}`);
const repository = computed(() => data.value.repository);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/repositories/${repository.value.id}`);
const tabs = computed<Record<string, string>>(() => ({
    deploys: t('Deploys'),
    webhook: t('Push deploys'),
    ...(data.value.canManage ? { settings: t('Settings') } : {}),
}));
const tab = computed(() => (typeof route.query.tab === 'string' && route.query.tab in tabs.value ? route.query.tab : 'deploys'));
const webhook = ref<{ secret: string; url: string } | null>(null);
const databaseModes = computed(() => [
    { value: 'full', label: t('Everything') },
    { value: 'sample', label: t('A sample of each table') },
    { value: 'schema', label: t('Schema only') },
]);
const previewSource = ref<string | null>(repository.value.previewDatabaseSourceWebsiteId === null ? '' : String(repository.value.previewDatabaseSourceWebsiteId));
const previewMode = ref<string | null>(repository.value.previewDatabaseMode);
const previewSources = computed(() => data.value.previewSources.map((source) => ({ value: source.value, label: t('A copy of :website', { website: source.label }) })));

/** Keep a new webhook secret to show once. */
function webhookTurnedOn(result: Record<string, unknown>): null {
    webhook.value = typeof result.secret === 'string' && typeof result.url === 'string' ? { secret: result.secret, url: result.url } : null;
    navigateTo({ query: { ...route.query, dialog: webhook.value ? 'webhook-secret' : undefined } });
    refreshPage();
    return null;
}

/**
 * Copy the webhook's address.
 *
 * @param text What to copy.
 */
async function copy(text: string) {
    const done = await navigator.clipboard.writeText(text).then(() => true, () => false);
    flash(done ? t('Copied') : t('Copy it by hand; your browser didn’t allow copying.'), done ? 'success' : 'warning');
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="repository.name" :description="`${repository.url} · ${repository.branch} → ${repository.website}`">
            <template v-if="data.canDeploy" #actions>
                <FormDialog
                    id="schedule-deploy"
                    :title="t('Book a deploy')"
                    :description="t('Deploy at a set time, such as a quiet hour tonight. It runs as you, and the environment’s approvals, locks, windows and freezes apply then.')"
                    :action="`${base}/scheduled-deploys`"
                    :submit="t('Book deploy')"
                >
                    <template #trigger="{ open }"><AcmeBtn icon="clock" :disabled="!repository.ready" @click="open">{{ t('Deploy later') }}</AcmeBtn></template>
                    <InputField id="schedule-deploy-at" name="deploy_at" type="datetime-local" :label="t('When')" required />
                    <InputField id="schedule-deploy-timezone" name="timezone" :label="t('Time zone')" :model-value="repository.timezone" maxlength="64" required />
                    <InputField id="schedule-deploy-ref" name="ref" :label="t('Branch, tag or commit')" :description="t('Optional. Leave empty for the latest :branch.', { branch: repository.branch })" maxlength="200" autocomplete="off" />
                </FormDialog>
                <FormDialog
                    id="deploy-ref"
                    :title="t('Deploy a specific version')"
                    :description="t('Deploy another branch, a release tag, or an exact commit. The environment’s approvals, locks and windows still apply.')"
                    :action="`${base}/builds`"
                    :submit="t('Deploy')"
                >
                    <template #trigger="{ open }"><AcmeBtn icon="branch" :disabled="!repository.ready" @click="open">{{ t('Deploy a version') }}</AcmeBtn></template>
                    <InputField id="deploy-ref-input" name="ref" :label="t('Branch, tag or commit')" placeholder="v1.4.0" maxlength="200" autocomplete="off" required autofocus />
                </FormDialog>
                <ApiForm :action="`${base}/builds`" class="!block">
                    <SubmitButton :disabled="!repository.ready">{{ t('Deploy now') }}</SubmitButton>
                </ApiForm>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeAlert v-if="!repository.ready" tone="warning">{{ t('Not ready: the website must be live on an active server, with a Git provider for this address.') }}</AcmeAlert>
            <p v-else class="text-sm text-muted">{{ t('Deploys to :website on :server', { website: repository.website, server: repository.server ?? '—' }) }}<template v-if="repository.environment"> · {{ repository.environment }}</template></p>

            <AcmeAlert v-if="data.scheduledDeploys.length > 0" tone="info" :title="t('Booked deploys')">
                <ul class="mt-1 space-y-1">
                    <li v-for="booked in data.scheduledDeploys" :key="booked.id" class="flex flex-wrap items-center gap-2">
                        <time :datetime="booked.runAt">{{ dateTime(booked.runAt) }}</time> · {{ booked.ref ?? repository.branch }} · {{ booked.creator ?? t('Someone') }}
                        <ApiForm v-if="data.canDeploy" :action="`${base}/scheduled-deploys/${booked.id}`" method="DELETE" class="!inline">
                            <button type="submit" class="text-xs font-medium underline">{{ t('Cancel') }}</button>
                        </ApiForm>
                    </li>
                </ul>
            </AcmeAlert>

            <PageTabs :tabs="tabs" :current="tab" :label="t('Repository sections')" :counts="{ deploys: data.builds.length }" />

            <AcmeCard v-if="tab === 'deploys'" :title="t('Deploys')" :description="t('Newest first. Open one for its log, or to redeploy or roll back.')" :padded="false">
                <p v-if="data.builds.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No deploys yet.') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="build in data.builds" :key="build.id">
                        <NuxtLink :to="`/projects/${project.id}/deploy/builds/${build.id}`" class="flex items-center gap-4 px-5 py-3.5 hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]">
                            <span class="w-14 font-mono text-sm text-muted">#{{ build.id }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ink">{{ (build.commitMessage ?? '').split('\n')[0] || t('No message') }}</span>
                                <span class="text-xs text-muted"><span class="font-mono">{{ build.revision ?? '—' }}</span> · {{ labels.trigger(build.trigger) }} · {{ build.requester ?? t('Push') }}<template v-if="build.createdAt"> · <RelativeTime :at="build.createdAt" /></template></span>
                            </span>
                            <BuildStatusBadge :status="build.status" />
                        </NuxtLink>
                    </li>
                </ul>
            </AcmeCard>

            <template v-if="tab === 'webhook'">
                <AcmeCard id="webhook" :title="t('Push deploys')" :description="t('A webhook from :host deploys each push to :branch.', { host: repository.host ?? t('your Git host'), branch: repository.branch })">
                    <ApiForm :action="`${base}/webhook`" :method="repository.webhookEnabled ? 'DELETE' : 'POST'" :after="repository.webhookEnabled ? undefined : webhookTurnedOn" class="!flex items-center justify-between gap-4 rounded-xl border border-line p-4">
                        <span>
                            <span class="block text-sm font-medium text-ink">{{ t('Deploy on push') }}</span>
                            <span class="text-xs text-muted">
                                {{ repository.webhookEnabled ? t('On: pushes deploy straight away') : t('Off: deploy by hand or on a schedule') }}
                                <template v-if="repository.webhookLastReceivedAt"> · <Rich :text="t('last push :when')"><template #when><RelativeTime :at="repository.webhookLastReceivedAt" /></template></Rich></template>
                            </span>
                        </span>
                        <ToggleField v-if="data.canManage" id="webhook-toggle" name="_toggle" :label="t('Deploy on push')" :checked="repository.webhookEnabled" :show-label="false" submit />
                    </ApiForm>
                    <dl v-if="repository.webhookEnabled" class="mt-4 grid gap-2 text-sm">
                        <div class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                            <dt class="w-20 shrink-0 text-xs text-muted">{{ t('URL') }}</dt>
                            <dd class="min-w-0 flex-1 truncate font-mono text-xs text-ink">{{ repository.webhookUrl }}</dd>
                            <AcmeBtn size="sm" variant="ghost" icon="copy" :label="t('Copy webhook URL')" @click="copy(repository.webhookUrl)" />
                        </div>
                        <div class="flex items-center gap-2 rounded-lg border border-line px-3 py-2">
                            <dt class="w-20 shrink-0 text-xs text-muted">{{ t('Secret') }}</dt>
                            <dd class="flex-1 font-mono text-xs text-ink">••••••••••••••••</dd>
                            <ApiForm v-if="data.canManage" :action="`${base}/webhook`" :after="webhookTurnedOn" class="!block">
                                <SubmitButton variant="secondary" size="sm">{{ t('Rotate') }}</SubmitButton>
                            </ApiForm>
                        </div>
                    </dl>
                </AcmeCard>
                <AcmeCard :title="t('Recent deliveries')" :padded="false">
                    <p v-if="data.deliveries.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No pushes have arrived yet.') }}</p>
                    <ul v-else class="divide-y divide-line text-sm">
                        <li v-for="delivery in data.deliveries" :key="delivery.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                            <span class="font-mono text-xs text-ink">{{ delivery.revision ?? '—' }}</span>
                            <span class="flex-1 text-muted">{{ labels.delivery(delivery.status) }}</span>
                            <RelativeTime v-if="delivery.createdAt" :at="delivery.createdAt" class="text-xs text-muted" />
                        </li>
                    </ul>
                </AcmeCard>
            </template>

            <template v-if="tab === 'settings' && data.canManage">
                <AcmeCard id="build-cache" :title="t('Build cache')" :description="t('Keeps Composer, npm, Yarn, pnpm and pip downloads on the server between deploys, so installs are faster. Clear it if a dependency seems stuck.')">
                    <div class="flex flex-wrap items-center gap-3">
                        <ApiForm :action="`${base}/build-cache`" method="PUT" class="!block">
                            <ToggleField id="build-cache-enabled" name="build_cache_enabled" :label="t('Cache dependencies between deploys')" :checked="repository.buildCacheEnabled" submit />
                        </ApiForm>
                        <ApiForm v-if="repository.buildCacheEnabled" :action="`${base}/build-cache`" method="PUT" class="ml-auto !block">
                            <input type="hidden" name="build_cache_enabled" value="1">
                            <input type="hidden" name="clear" value="1">
                            <SubmitButton variant="secondary" size="sm">{{ t('Clear build cache') }}</SubmitButton>
                        </ApiForm>
                    </div>
                </AcmeCard>

                <AcmeCard v-if="!repository.isPreview" id="previews" :title="t('Pull-request previews')" :description="t('Each pull request into :branch gets its own website on :server, deployed from its branch. Push deploys must be on for the webhook to arrive; forks don’t get previews.', { branch: repository.branch, server: repository.server ?? t('the website’s server') })">
                    <ApiForm :action="`${base}/previews`" method="PUT" class="grid items-start gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2"><ToggleField id="previews-enabled" name="previews_enabled" :label="t('Make previews of pull requests')" :checked="repository.previewsEnabled" /></div>
                        <InputField
                            name="preview_domain"
                            :label="t('Preview domain')"
                            :model-value="repository.previewDomain"
                            placeholder="preview.example.com"
                            maxlength="200"
                            :description="t('Previews are served at pr-12-:project.<domain>; point wildcard DNS (*.<domain>) at the server.', { project: data.projectSlug })"
                        />
                        <InputField name="preview_ttl_hours" type="number" min="1" max="720" :label="t('Close after (hours without changes)')" :model-value="String(repository.previewTtlHours)" required />
                        <div class="sm:col-span-2">
                            <TextareaField name="preview_initialization_command" :label="t('Set-up command (optional)')" rows="2" :model-value="repository.previewInitializationCommand" :description="t('Runs once on each new preview after its first deploy, e.g. php artisan migrate --seed.')" />
                        </div>
                        <SelectField
                            v-model="previewSource"
                            name="preview_database_source_website_id"
                            :label="t('Start each preview’s database from')"
                            :placeholder="t('An empty database')"
                            :options="previewSources"
                            :description="t('New previews get a copy of this website’s database before their first deploy, so migrations and reviewers see real-looking data. Mind personal data: prefer a staging copy over production.')"
                        />
                        <SelectField
                            v-model="previewMode"
                            name="preview_database_mode"
                            :label="t('How much to copy')"
                            :options="databaseModes"
                            :description="t('A sample takes the first :rows rows of each table: a quick branch of a big database. Schema only copies the tables without data, for your seeders to fill.', { rows: number(data.sampleRows) })"
                        />
                        <div class="sm:col-span-2">
                            <ToggleField
                                id="previews-anonymise"
                                name="preview_database_anonymise"
                                :label="t('Mask personal data')"
                                :checked="repository.previewDatabaseAnonymise"
                                :description="t('Emails, names, phone numbers, addresses, IP addresses and dates of birth are replaced in each preview’s copy, by column name.')"
                            />
                        </div>
                        <div class="sm:col-span-2"><SubmitButton>{{ t('Save preview settings') }}</SubmitButton></div>
                    </ApiForm>
                </AcmeCard>

                <AcmeCard :title="t('Build settings')" :description="t('Changes apply to the next deploy.')">
                    <ApiForm :action="base" method="PUT" class="grid gap-5">
                        <RepositoryFields :options="data.options" :repository="repository" />
                        <div><SubmitButton>{{ t('Save settings') }}</SubmitButton></div>
                    </ApiForm>
                </AcmeCard>

                <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-rose-500/30 bg-rose-500/[.03] p-5">
                    <span><span class="block font-semibold text-rose-700 dark:text-rose-300">{{ t('Remove this repository') }}</span><span class="text-sm text-muted">{{ t('Deploys stop; the website keeps its current release and the history stays.') }}</span></span>
                    <DeleteDialog id="delete-repository" :title="t('Remove :name?', { name: repository.name })" :description="t('The website and its releases aren’t touched.')" :action="base" :submit-label="t('Remove repository')">
                        <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Remove repository') }}</AcmeBtn></template>
                    </DeleteDialog>
                </section>
            </template>
        </div>

        <UiDialog v-if="webhook" id="webhook-secret" :title="t('Webhook secret')" :description="t('Add a push webhook with this URL and secret (JSON payloads). The secret is shown once.')">
            <div class="grid gap-3">
                <AcmeAlert tone="warning">{{ t('Copy it now') }}</AcmeAlert>
                <AcmeCodeBlock :title="t('URL')" :code="webhook.url" :dark="false" />
                <AcmeCodeBlock :title="t('Secret')" :code="webhook.secret" :dark="false" />
            </div>
        </UiDialog>
    </div>
</template>
