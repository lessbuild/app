<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/**
 * Connecting apps: each environment's ingest keys and whether data is arriving, an example for the chosen stack,
 * OpenTelemetry settings, ticket trackers and browser error tracking.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type SetupEnvironment = {
    id: string;
    name: string;
    description: string;
    events: number;
    state: string;
    tone: Tone;
    tokens: Array<{ id: number; name: string; prefix: string; lastUsedAt: string | null; expiresAt: string | null }>;
    browserKey: string | null;
    browserOrigins: string;
};
type SetupPage = {
    overview: ProjectOverview;
    environments: SetupEnvironment[];
    browserScript: string;
    stack: string;
    stacks: Record<string, string>;
    guide: { install: string; token: string; code: string; verification: string };
    otlp: string;
    trackers: Array<{ id: number; name: string; kind: string; destination: string }>;
    trackerKinds: Record<string, string>;
    canManage: boolean;
};
const { t, tc, number, date } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<SetupPage>(() => `/projects/${route.params.project}/monitoring/setup`, () => ({ stack: text(route.query.stack) }));
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring`);
const secrets = useSecrets();
const stack = ref<string | null>(data.value.stack);
const stacks = computed(() => Object.entries(data.value.stacks).map(([value, label]) => ({ value, label })));
const expiries = computed(() => [{ value: '', label: t('Never') }, ...[30, 90, 365].map((days) => ({ value: String(days), label: tc('In :count day|In :count days', days) }))]);
const trackerKind = ref<string | null>('github');
const trackerKinds = computed(() => Object.entries(data.value.trackerKinds).map(([value, label]) => ({ value, label })));
// Split so the closing tag doesn't end this script block.
const snippet = (key: string) => `<script src="${data.value.browserScript}" data-key="${key}" data-release="YOUR_RELEASE" defer></` + 'script>';
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Connect your app')" :description="t('Each environment has its own ingest keys. Send events as JSON or through OpenTelemetry.')" />

        <section class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Environments')">
                <li v-for="environment in data.environments" :key="environment.id" class="grid gap-3 px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-extrabold text-ink">{{ environment.name }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ environment.description }} · {{ tc(':count event received|:count events received', environment.events, { count: number(environment.events) }) }}</p>
                        </div>
                        <span class="flex items-center gap-2">
                            <Badge :tone="environment.tone">{{ environment.state }}</Badge>
                            <UiButton :to="`/projects/${project.id}/monitoring/environments/${environment.id}/deliveries`" variant="quiet" size="sm">{{ t('Deliveries') }}</UiButton>
                        </span>
                    </div>
                    <Alert v-if="secrets?.ingest_key && secrets.ingest_environment_id === environment.id" tone="success" role="status">
                        <p class="font-bold">{{ t('Copy this ingest key now. It won’t be shown again.') }}</p>
                        <CodeBlock :code="secrets.ingest_key" class="mt-2 whitespace-pre-wrap break-all" />
                    </Alert>
                    <div v-for="token in environment.tokens" :key="token.id" class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-surface-muted px-3 py-2 text-sm">
                        <span>
                            <span class="font-semibold text-ink">{{ token.name }}</span> <code class="text-xs text-muted">{{ token.prefix }}…</code>
                            <span class="text-xs text-muted">
                                ·
                                <Rich v-if="token.lastUsedAt" :text="t('used :time')"><template #time><RelativeTime :at="token.lastUsedAt" /></template></Rich>
                                <template v-else>{{ t('never used') }}</template>
                                <template v-if="token.expiresAt"> · {{ t('expires :date', { date: date(token.expiresAt) }) }}</template>
                            </span>
                        </span>
                        <span v-if="data.canManage" class="flex gap-1">
                            <ApiForm :action="`${base}/keys/${token.id}/rotate`"><SubmitButton variant="quiet" size="sm">{{ t('Replace') }}</SubmitButton></ApiForm>
                            <DeleteDialog :id="`revoke-key-${token.id}`" :title="t('Revoke :name?', { name: token.name })" :description="t('Apps using this key can no longer send data.')" :action="`${base}/keys/${token.id}`" :submit-label="t('Revoke')">
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Revoke') }}</UiButton></template>
                            </DeleteDialog>
                        </span>
                    </div>
                    <ApiForm v-if="data.canManage" :action="`${base}/environments/${environment.id}/keys`" class="flex flex-wrap items-end gap-2">
                        <InputField :id="`key-name-${environment.id}`" name="name" :label="t('New key name')" :model-value="t('Collector')" maxlength="120" required />
                        <SelectField :id="`key-expiry-${environment.id}`" name="expires_in_days" :label="t('Expires')" :options="expiries" model-value="" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Create key') }}</SubmitButton>
                    </ApiForm>
                </li>
            </ul>
        </section>

        <SettingsSection :title="t('Send events')" :description="t('Pick your stack for a working example. Replace the key placeholder with an ingest key from above.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="navigateTo({ query: { stack: stack ?? undefined } })">
                    <SelectField id="setup-stack" v-model="stack" name="stack" :label="t('Stack')" :options="stacks" />
                    <UiButton type="submit" size="sm">{{ t('Show') }}</UiButton>
                </form>
                <p class="text-sm text-muted">{{ data.guide.install }}</p>
                <p class="text-sm text-muted">{{ data.guide.token }}</p>
                <CodeBlock :code="data.guide.code" class="overflow-x-auto text-xs" />
                <p class="text-xs text-muted">{{ data.guide.verification }}</p>
            </div>
        </SettingsSection>

        <SettingsSection :title="t('OpenTelemetry')" :description="t('Any OpenTelemetry SDK or collector can export traces, logs and metrics over OTLP/HTTP with JSON.')">
            <div class="p-4 sm:p-6"><CodeBlock :code="data.otlp" class="overflow-x-auto text-xs" /></div>
        </SettingsSection>

        <SettingsSection id="trackers" :title="t('Ticket trackers')" :description="t('File tickets for issues in GitHub Issues, Linear or Jira, from each issue’s page. Credentials are your own and stored encrypted; give them only the access to create issues.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <p v-if="data.trackers.length === 0" class="text-sm text-muted">{{ t('No trackers connected.') }}</p>
                <div v-for="tracker in data.trackers" :key="tracker.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold text-ink">{{ tracker.name }}</span> <span class="text-muted">· {{ tracker.kind }} · {{ tracker.destination }}</span></span>
                    <DeleteDialog v-if="data.canManage" :id="`disconnect-tracker-${tracker.id}`" :title="t('Disconnect :name?', { name: tracker.name })" :description="t('Tickets already filed stay where they are.')" :action="`${base}/trackers/${tracker.id}`" :submit-label="t('Disconnect')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Disconnect') }}</UiButton></template>
                    </DeleteDialog>
                </div>
                <ApiForm v-if="data.canManage" :action="`${base}/trackers`" class="grid items-start gap-3 border-t border-line pt-4 sm:grid-cols-2">
                    <SelectField id="tracker-kind" v-model="trackerKind" name="kind" :label="t('Tracker')" :options="trackerKinds" />
                    <InputField id="tracker-name" name="name" :label="t('Name')" maxlength="80" placeholder="Engineering" />
                    <template v-if="trackerKind === 'github'">
                        <InputField id="tracker-repository" name="repository" :label="t('Repository')" placeholder="acme/shop" maxlength="200" required />
                        <PasswordField id="tracker-github-token" name="token" :label="t('Token')" :description="t('A fine-grained token with Issues read and write.')" maxlength="500" autocomplete="off" required />
                    </template>
                    <template v-else-if="trackerKind === 'linear'">
                        <PasswordField id="tracker-api-key" name="api_key" :label="t('API key')" maxlength="500" autocomplete="off" required />
                        <InputField id="tracker-team" name="team_id" :label="t('Team ID')" maxlength="100" required />
                    </template>
                    <template v-else>
                        <InputField id="tracker-site" name="site" type="url" :label="t('Site')" placeholder="https://acme.atlassian.net" maxlength="200" required />
                        <InputField id="tracker-email" name="email" type="email" :label="t('Email')" maxlength="200" required />
                        <PasswordField id="tracker-jira-token" name="token" :label="t('API token')" maxlength="500" autocomplete="off" required />
                        <InputField id="tracker-project" name="project_key" :label="t('Project key')" placeholder="OPS" maxlength="20" required />
                    </template>
                    <div class="sm:col-span-2"><SubmitButton variant="secondary">{{ t('Connect') }}</SubmitButton></div>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection id="browser-errors" :title="t('Browser errors')" :description="t('Catch JavaScript errors and unhandled promise rejections in your visitors’ browsers. They become issues from the “browser” service, beside your server errors.')">
            <div class="grid gap-6 p-4 sm:p-6">
                <div v-for="environment in data.environments" :key="environment.id" class="grid gap-3">
                    <p class="flex items-center gap-2 font-extrabold text-ink">{{ environment.name }} <Badge :tone="environment.browserKey ? 'success' : 'neutral'">{{ environment.browserKey ? t('On') : t('Off') }}</Badge></p>
                    <template v-if="environment.browserKey">
                        <CodeBlock :code="snippet(environment.browserKey)" class="whitespace-pre-wrap break-all text-xs" />
                        <p class="text-xs text-muted">{{ t('Put it in the <head> of every page. Set data-release to the version you deployed, so errors are tied to releases. Report caught errors with window.buildpusherError(error).') }}</p>
                    </template>
                    <div v-if="data.canManage" class="flex flex-wrap items-end gap-3">
                        <ApiForm :action="`${base}/environments/${environment.id}/browser`" method="PUT" class="flex min-w-0 flex-1 flex-wrap items-end gap-3">
                            <input type="hidden" name="enabled" value="1">
                            <div class="min-w-64 flex-1">
                                <InputField :id="`browser-origins-${environment.id}`" name="origins" :label="t('Origins (space or comma separated)')" :model-value="environment.browserOrigins" maxlength="2000" placeholder="https://example.com https://www.example.com" required />
                            </div>
                            <SubmitButton variant="secondary">{{ environment.browserKey ? t('Save origins') : t('Turn on') }}</SubmitButton>
                        </ApiForm>
                        <ApiForm v-if="environment.browserKey" :action="`${base}/environments/${environment.id}/browser`" method="PUT">
                            <input type="hidden" name="enabled" value="0">
                            <SubmitButton variant="quiet">{{ t('Turn off') }}</SubmitButton>
                        </ApiForm>
                    </div>
                </div>
            </div>
        </SettingsSection>
    </div>
</template>
