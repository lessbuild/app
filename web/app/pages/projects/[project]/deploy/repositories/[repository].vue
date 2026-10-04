<script setup lang="ts">
import type { RepositoryPage } from '~/types/deploy';

/**
 * One repository: deploying it (now, a chosen version, or later), its deploys, push deploys through a webhook, and
 * its settings (build cache, commands, pull-request previews, removing it).
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
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="repository.name" :description="`${repository.url} · ${repository.branch} → ${repository.website}`" />

        <section class="ui-card flex flex-wrap items-center justify-between gap-4 p-5">
            <div class="text-sm">
                <p>{{ t('Deploys to :website on :server', { website: repository.website, server: repository.server ?? '—' }) }}<template v-if="repository.environment"> · {{ repository.environment }}</template></p>
                <p v-if="!repository.ready" class="text-danger">{{ t('Not ready: the website must be live on an active server, with a Git provider for this address.') }}</p>
            </div>
            <div v-if="data.canDeploy" class="flex flex-wrap gap-2">
                <ApiForm :action="`${base}/builds`">
                    <SubmitButton :disabled="!repository.ready">{{ t('Deploy :branch', { branch: repository.branch }) }}</SubmitButton>
                </ApiForm>
                <FormDialog
                    id="deploy-ref"
                    :title="t('Deploy a specific version')"
                    :description="t('Deploy another branch, a release tag, or an exact commit. The environment’s approvals, locks and windows still apply.')"
                    :action="`${base}/builds`"
                    :submit="t('Deploy')"
                >
                    <template #trigger="{ open }"><UiButton :disabled="!repository.ready" @click="open">{{ t('Deploy a version…') }}</UiButton></template>
                    <InputField id="deploy-ref-input" name="ref" :label="t('Branch, tag or commit')" placeholder="v1.4.0" maxlength="200" autocomplete="off" required autofocus />
                </FormDialog>
                <FormDialog
                    id="schedule-deploy"
                    :title="t('Book a deploy')"
                    :description="t('Deploy at a set time, such as a quiet hour tonight. It runs as you, and the environment’s approvals, locks, windows and freezes apply then.')"
                    :action="`${base}/scheduled-deploys`"
                    :submit="t('Book deploy')"
                >
                    <template #trigger="{ open }"><UiButton variant="quiet" @click="open">{{ t('Deploy later…') }}</UiButton></template>
                    <div class="grid items-start gap-5 sm:grid-cols-2">
                        <InputField id="schedule-deploy-at" name="deploy_at" type="datetime-local" :label="t('When')" required />
                        <InputField id="schedule-deploy-timezone" name="timezone" :label="t('Time zone')" :model-value="repository.timezone" maxlength="64" required />
                        <div class="sm:col-span-2">
                            <InputField id="schedule-deploy-ref" name="ref" :label="t('Branch, tag or commit')" :description="t('Optional. Leave empty for the latest :branch.', { branch: repository.branch })" maxlength="200" autocomplete="off" />
                        </div>
                    </div>
                </FormDialog>
            </div>
        </section>

        <section v-if="data.scheduledDeploys.length > 0" class="ui-card grid gap-2 p-5" aria-labelledby="booked-heading">
            <h2 id="booked-heading" class="text-sm font-extrabold text-ink">{{ t('Booked deploys') }}</h2>
            <div v-for="booked in data.scheduledDeploys" :key="booked.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span>
                    <time :datetime="booked.runAt" class="font-semibold">{{ dateTime(booked.runAt) }}</time>
                    · {{ booked.ref ?? repository.branch }} · <span class="text-muted">{{ booked.creator ?? t('Someone') }}</span>
                </span>
                <ApiForm v-if="data.canDeploy" :action="`${base}/scheduled-deploys/${booked.id}`" method="DELETE">
                    <SubmitButton variant="quiet" size="sm">{{ t('Cancel') }}</SubmitButton>
                </ApiForm>
            </div>
        </section>

        <PageTabs :tabs="tabs" :current="tab" :label="t('Repository sections')" />

        <SettingsSection v-if="tab === 'deploys'" :title="t('Deploys')" :description="t('Newest first. Open one for its log, or to redeploy or roll back.')">
            <p v-if="data.builds.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No deploys yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="build in data.builds" :key="build.id">
                    <NuxtLink :to="`/projects/${project.id}/deploy/builds/${build.id}`" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-surface-muted sm:px-6">
                        <span class="min-w-0 text-sm">
                            <span class="font-bold text-primary">#{{ build.id }}</span>
                            <span class="ml-2 font-mono text-xs text-muted">{{ build.revision ?? '—' }}</span>
                            <span class="ml-2 text-ink">{{ (build.commitMessage ?? '').split('\n')[0] }}</span>
                            <span class="block text-xs text-muted">
                                {{ labels.trigger(build.trigger) }} · {{ build.requester ?? t('Push') }}<template v-if="build.createdAt"> · <RelativeTime :at="build.createdAt" /></template>
                            </span>
                        </span>
                        <BuildStatusBadge :status="build.status" />
                    </NuxtLink>
                </li>
            </ul>
        </SettingsSection>

        <SettingsSection
            v-if="tab === 'webhook'"
            id="webhook"
            :title="t('Push deploys')"
            :description="t('A webhook from :host deploys each push to :branch.', { host: repository.host ?? t('your Git host'), branch: repository.branch })"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <p class="flex items-center gap-2 text-sm">
                    <Badge :tone="repository.webhookEnabled ? 'success' : 'neutral'">{{ repository.webhookEnabled ? t('On') : t('Off') }}</Badge>
                    <span v-if="repository.webhookLastReceivedAt" class="text-muted">
                        <Rich :text="t('last push :when')"><template #when><RelativeTime :at="repository.webhookLastReceivedAt" /></template></Rich>
                    </span>
                </p>
                <ul v-if="data.deliveries.length > 0" class="grid gap-1 text-xs text-muted">
                    <li v-for="delivery in data.deliveries" :key="delivery.id">
                        <span class="font-mono">{{ delivery.revision ?? '—' }}</span> · {{ labels.delivery(delivery.status) }}<template v-if="delivery.createdAt"> · <RelativeTime :at="delivery.createdAt" /></template>
                    </li>
                </ul>
                <div v-if="data.canManage" class="flex flex-wrap gap-2">
                    <ApiForm :action="`${base}/webhook`" :after="webhookTurnedOn">
                        <SubmitButton variant="secondary" size="sm">{{ repository.webhookEnabled ? t('New secret') : t('Turn on') }}</SubmitButton>
                    </ApiForm>
                    <ApiForm v-if="repository.webhookEnabled" :action="`${base}/webhook`" method="DELETE">
                        <SubmitButton variant="quiet" size="sm">{{ t('Turn off') }}</SubmitButton>
                    </ApiForm>
                </div>
            </div>
        </SettingsSection>

        <template v-if="tab === 'settings' && data.canManage">
            <SettingsSection id="build-cache" :title="t('Build cache')" :description="t('Keeps Composer, npm, Yarn, pnpm and pip downloads on the server between deploys, so installs are faster. Clear it if a dependency seems stuck.')">
                <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-6">
                    <ApiForm :action="`${base}/build-cache`" method="PUT" class="flex flex-wrap items-center gap-3">
                        <CheckboxField id="build-cache-enabled" name="build_cache_enabled" unchecked-value="0" :label="t('Cache dependencies between deploys')" :checked="repository.buildCacheEnabled" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                    <ApiForm v-if="repository.buildCacheEnabled" :action="`${base}/build-cache`" method="PUT">
                        <input type="hidden" name="build_cache_enabled" value="1">
                        <input type="hidden" name="clear" value="1">
                        <SubmitButton variant="quiet" size="sm">{{ t('Clear build cache') }}</SubmitButton>
                    </ApiForm>
                </div>
            </SettingsSection>

            <SettingsSection :title="t('Settings')" :description="t('Changes apply to the next deploy.')">
                <ApiForm :action="base" method="PUT" class="grid gap-5 p-4 sm:p-6">
                    <RepositoryFields :options="data.options" :repository="repository" />
                    <div class="flex justify-end"><SubmitButton>{{ t('Save repository') }}</SubmitButton></div>
                </ApiForm>
            </SettingsSection>

            <SettingsSection
                v-if="!repository.isPreview"
                id="previews"
                :title="t('Pull-request previews')"
                :description="t('Each pull request into :branch gets its own website on :server, deployed from its branch. Push deploys must be on for the webhook to arrive; forks don’t get previews.', { branch: repository.branch, server: repository.server ?? t('the website’s server') })"
            >
                <ApiForm :action="`${base}/previews`" method="PUT" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                    <div class="sm:col-span-2"><CheckboxField name="previews_enabled" :label="t('Make previews of pull requests')" :checked="repository.previewsEnabled" /></div>
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
                    <div class="sm:col-span-2">
                        <SelectField
                            v-model="previewSource"
                            name="preview_database_source_website_id"
                            :label="t('Start each preview’s database from')"
                            :placeholder="t('An empty database')"
                            :options="previewSources"
                            :description="t('New previews get a copy of this website’s database before their first deploy, so migrations and reviewers see real-looking data. Mind personal data: prefer a staging copy over production.')"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <SelectField
                            v-model="previewMode"
                            name="preview_database_mode"
                            :label="t('How much to copy')"
                            :options="databaseModes"
                            :description="t('A sample takes the first :rows rows of each table: a quick branch of a big database. Schema only copies the tables without data, for your seeders to fill.', { rows: number(data.sampleRows) })"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <CheckboxField
                            name="preview_database_anonymise"
                            unchecked-value="0"
                            :label="t('Mask personal data')"
                            :checked="repository.previewDatabaseAnonymise"
                            :description="t('Emails, names, phone numbers, addresses, IP addresses and dates of birth are replaced in each preview’s copy, by column name.')"
                        />
                    </div>
                    <div class="flex justify-end sm:col-span-2"><SubmitButton>{{ t('Save preview settings') }}</SubmitButton></div>
                </ApiForm>
            </SettingsSection>

            <SettingsSection :title="t('Remove this repository')" :description="t('Deploys stop; the website keeps its current release and the history stays.')">
                <div class="p-4 sm:p-6">
                    <DeleteDialog
                        id="delete-repository"
                        :title="t('Remove :name?', { name: repository.name })"
                        :description="t('The website and its releases aren’t touched.')"
                        :action="base"
                        :submit-label="t('Remove repository')"
                    >
                        <template #trigger="{ open }"><UiButton variant="danger" @click="open">{{ t('Remove repository') }}</UiButton></template>
                    </DeleteDialog>
                </div>
            </SettingsSection>
        </template>

        <UiDialog v-if="webhook" id="webhook-secret" :title="t('Webhook secret')" :description="t('Add a push webhook with this URL and secret (JSON payloads). The secret is shown once.')">
            <div class="grid gap-3">
                <Alert tone="warning">{{ t('Copy it now') }}</Alert>
                <CodeBlock :code="webhook.url" class="break-all" />
                <CodeBlock :code="webhook.secret" class="break-all" />
            </div>
        </UiDialog>
    </div>
</template>
