<script setup lang="ts">
import type { ProjectTemplate } from '~/types/projects';

/**
 * Set up a whole project from a template in four steps: the template, the project and its domain, where it runs and
 * deploys from, and a check. Saved templates (made from a project's settings) can be removed here.
 */
definePageMeta({ layout: 'app' });
const { t, tc } = useT();
const route = useRoute();
type Choice = { id: number; label: string };
const { data } = await useApi<{ account: { id: string; name: string }; templates: ProjectTemplate[]; servers: Choice[]; gitProviders: Choice[] }>('/projects/templates');

const step = ref(0);
const busy = ref(false);
const error = ref<string | null>(null);
const errors = ref<ValidationError | null>(null);
const requested = typeof route.query.template === 'string' ? route.query.template : '';
const form = reactive({
    template: data.value.templates.some((template) => template.key === requested) ? requested : (data.value.templates[0]?.key ?? ''),
    name: '',
    domain: '',
    server_id: String(data.value.servers[0]?.id ?? ''),
    provider_id: String(data.value.gitProviders[0]?.id ?? ''),
    repository_url: '',
    branch: 'main',
});
const ready = computed(() => data.value.servers.length > 0 && data.value.gitProviders.length > 0);
const chosen = computed(() => data.value.templates.find((template) => template.key === form.template));
const label = (choices: Choice[], id: string) => choices.find((choice) => String(choice.id) === id)?.label ?? '';

/** Check that each field in a step is filled in. */
const required = (...fields: Array<keyof typeof form>) => () => (fields.some((field) => form[field].trim() === '') ? t('Fill in every field to continue.') : null);

const steps = computed(() => [
    { id: 'template', title: t('Template'), description: t('Set up a project in one go: its services, a website on one of your servers, the repository that deploys to it, uptime monitoring and analytics.'), validate: required('template') },
    { id: 'project', title: t('Project and domain'), validate: required('name', 'domain') },
    { id: 'source', title: t('Server and repository'), description: t('The website is created on the server and set up over SSH; pushes to the branch deploy once it’s live.'), validate: required('server_id', 'provider_id', 'repository_url', 'branch') },
    { id: 'review', title: t('Check and create') },
]);

/** Which step holds each field, to go back to the first one with an error. */
const stepOf: Record<string, number> = { template: 0, name: 1, domain: 1, server_id: 2, provider_id: 2, repository_url: 2, branch: 2 };

async function create() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', '/projects/templates', { ...form, server_id: Number(form.server_id), provider_id: Number(form.provider_id) });
        flash(result.message);
        await navigateTo(local(result.redirect));
    } catch (problem) {
        if (problem instanceof ValidationError) {
            errors.value = problem;
            step.value = Math.min(...Object.keys(problem.errors).map((field) => stepOf[field] ?? 3));
        }
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :eyebrow="data.account.name" :title="t('Start from a template')" :breadcrumbs="[{ label: t('Projects'), to: '/dashboard' }]" />

        <Alert v-if="!ready" tone="warning">
            <p v-if="data.servers.length === 0">{{ t('You need an active app server first.') }} <NuxtLink to="/dashboard" class="font-bold underline">{{ t('Create one from a project’s Infrastructure') }}</NuxtLink></p>
            <p v-if="data.gitProviders.length === 0">{{ t('Connect GitHub, GitLab or Bitbucket first.') }} <NuxtLink to="/account/providers" class="font-bold underline">{{ t('Providers') }}</NuxtLink></p>
        </Alert>

        <div class="ui-card max-w-4xl p-5 sm:p-6">
            <Wizard v-model="step" :steps="steps" :busy="busy || !ready" :error="error" :finish-label="t('Set up project')" @finish="create">
                <template #template>
                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="sr-only">{{ t('Templates') }}</legend>
                        <label
                            v-for="template in data.templates"
                            :key="template.key"
                            :class="['flex cursor-pointer items-start gap-3 rounded-card border p-4 transition', form.template === template.key ? 'border-primary bg-primary-soft' : 'border-line hover:border-subtle']"
                        >
                            <input v-model="form.template" type="radio" name="template" class="ui-check mt-1" :value="template.key">
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2 text-sm font-extrabold text-ink">{{ template.name }}<Badge v-if="template.savedId">{{ t('Yours') }}</Badge></span>
                                <span class="mt-0.5 block text-xs leading-5 text-muted">{{ template.description }}</span>
                                <span v-if="template.savedId" class="mt-1 block text-xs text-muted">
                                    {{ [tc(':count other environment|:count other environments', template.environments), tc(':count uptime check|:count uptime checks', template.monitors), tc(':count goal|:count goals', template.goals)].join(' · ') }}
                                </span>
                            </span>
                        </label>
                    </fieldset>
                    <ul v-if="data.templates.some((template) => template.savedId)" class="flex flex-wrap gap-2">
                        <li v-for="template in data.templates.filter((item) => item.savedId)" :key="template.key">
                            <DeleteDialog :id="`remove-template-${template.savedId}`" :title="t('Remove template')" :description="template.name" :action="`/api/app/projects/templates/${template.savedId}`" :submit-label="t('Remove template')">
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove template') }}: {{ template.name }}</UiButton></template>
                            </DeleteDialog>
                        </li>
                    </ul>
                </template>
                <template #project>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiField id="template-name" :label="t('Project name')" :error="errors?.first('name')">
                            <input id="template-name" v-model="form.name" class="ui-input" maxlength="100" autocomplete="off" required :aria-invalid="!!errors?.first('name') || undefined">
                        </UiField>
                        <UiField id="template-domain" :label="t('Domain')" :description="t('Point its DNS at the server; HTTPS is set up automatically.')" :error="errors?.first('domain')">
                            <input id="template-domain" v-model="form.domain" class="ui-input" placeholder="app.example.com" maxlength="255" required aria-describedby="template-domain-help" :aria-invalid="!!errors?.first('domain') || undefined">
                        </UiField>
                    </div>
                </template>
                <template #source>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiField id="template-server" :label="t('Server')" :error="errors?.first('server_id')">
                            <select id="template-server" v-model="form.server_id" class="ui-input" required>
                                <option v-for="server in data.servers" :key="server.id" :value="String(server.id)">{{ server.label }}</option>
                            </select>
                        </UiField>
                        <UiField id="template-provider" :label="t('Git provider')" :error="errors?.first('provider_id')">
                            <select id="template-provider" v-model="form.provider_id" class="ui-input" required>
                                <option v-for="provider in data.gitProviders" :key="provider.id" :value="String(provider.id)">{{ provider.label }}</option>
                            </select>
                        </UiField>
                        <UiField id="template-repository" :label="t('Repository')" :error="errors?.first('repository_url')">
                            <input id="template-repository" v-model="form.repository_url" class="ui-input" placeholder="github.com/acme/app" maxlength="255" required>
                        </UiField>
                        <UiField id="template-branch" :label="t('Branch')" :error="errors?.first('branch')">
                            <input id="template-branch" v-model="form.branch" class="ui-input" maxlength="255" required>
                        </UiField>
                    </div>
                </template>
                <template #review>
                    <dl class="grid gap-3 rounded-card bg-surface-muted p-4 text-sm sm:grid-cols-[9rem_1fr]">
                        <dt class="font-bold text-muted">{{ t('Template') }}</dt>
                        <dd class="text-ink">{{ chosen?.name }}</dd>
                        <dt class="font-bold text-muted">{{ t('Project name') }}</dt>
                        <dd class="text-ink">{{ form.name }}</dd>
                        <dt class="font-bold text-muted">{{ t('Domain') }}</dt>
                        <dd class="text-ink">{{ form.domain }}</dd>
                        <dt class="font-bold text-muted">{{ t('Server') }}</dt>
                        <dd class="text-ink">{{ label(data.servers, form.server_id) }}</dd>
                        <dt class="font-bold text-muted">{{ t('Repository') }}</dt>
                        <dd class="text-ink">{{ form.repository_url }} · {{ form.branch }} ({{ label(data.gitProviders, form.provider_id) }})</dd>
                    </dl>
                </template>
            </Wizard>
        </div>
    </div>
</template>
