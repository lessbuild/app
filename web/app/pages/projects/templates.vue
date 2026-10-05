<script setup lang="ts">
import type { ProjectTemplate } from '~/types/projects';

/**
 * Set up a whole project from a template (the Acme theme's templates page): pick one, then fill in the few details it
 * needs (its name and domain, the server it runs on and the repository it deploys from). Saved templates (made from a
 * project's settings) can be removed here. Opened from another page, it shows in a drawer over it.
 */
definePageMeta({ layout: 'app' });
const { t, tc } = useT();
const route = useRoute();
const dialogLink = useDialogLink();
type Choice = { id: number; label: string };
const { data } = await useApi<{ account: { id: string; name: string }; templates: ProjectTemplate[]; servers: Choice[]; gitProviders: Choice[] }>('/projects/templates');

const busy = ref(false);
const error = ref<string | null>(null);
const errors = ref<ValidationError | null>(null);
const shell = useShell();
const panel = usePagePanel();
const requested = typeof route.query.template === 'string' ? route.query.template : '';
const form = reactive({
    template: data.value.templates.some((template) => template.key === requested) ? requested : '',
    name: '',
    domain: '',
    server_id: String(data.value.servers[0]?.id ?? ''),
    provider_id: String(data.value.gitProviders[0]?.id ?? ''),
    repository_url: '',
    branch: 'main',
});
const ready = computed(() => data.value.servers.length > 0 && data.value.gitProviders.length > 0);
const chosen = computed(() => data.value.templates.find((template) => template.key === form.template));
/** A service's name, from the shell's navigation. */
const serviceName = (key: string) => shell.value?.primaryNav.find((item) => item.service === key)?.label ?? key;

/**
 * Pick a template and move to its form.
 *
 * @param key The template.
 */
function choose(key: string) {
    form.template = key;
    nextTick(() => document.getElementById('template-form')?.scrollIntoView({ behavior: 'smooth' }));
}

async function create() {
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ redirect: string; message: string }>('POST', '/projects/templates', { ...form, server_id: Number(form.server_id), provider_id: Number(form.provider_id) });
        flash(result.message);
        // In the drawer, the new project shows up on the page underneath; on its own page, open the new project.
        if (panel) {
            panel.close();
            await refreshPage();
            return;
        }
        await navigateTo(local(result.redirect));
    } catch (problem) {
        if (problem instanceof ValidationError) {
            errors.value = problem;
        }
        error.value = problem instanceof Error ? problem.message : t('Something went wrong. Try again.');
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <PlatformHeader :title="t('Start from a template')" :subtitle="t('Set up a project in one go: its services, a website on one of your servers, the repository that deploys to it, uptime monitoring and analytics.')" />
        <div class="space-y-6">
            <AcmeAlert v-if="!ready" tone="warning">
                <p v-if="data.servers.length === 0">{{ t('You need an active app server first.') }} <NuxtLink to="/dashboard" class="font-medium underline">{{ t('Create one from a project’s Infrastructure') }}</NuxtLink></p>
                <p v-if="data.gitProviders.length === 0">{{ t('Connect GitHub, GitLab or Bitbucket first.') }} <NuxtLink :to="dialogLink('add-provider')" class="font-medium underline">{{ t('Connect a provider') }}</NuxtLink></p>
            </AcmeAlert>

            <ul class="grid gap-4 sm:grid-cols-2 @5xl:grid-cols-3" role="radiogroup" :aria-label="t('Templates')">
                <li v-for="template in data.templates" :key="template.key">
                    <button type="button" role="radio" :aria-checked="form.template === template.key" :class="['flex h-full w-full flex-col rounded-2xl border bg-surface p-5 text-left shadow-card transition hover:-translate-y-0.5 hover:shadow-lift', form.template === template.key ? 'border-accent ring-2 ring-accent' : 'border-line']" @click="choose(template.key)">
                        <span class="flex items-center gap-3"><AcmeIconBubble :icon="template.icon" size="md" /><span class="font-semibold text-ink">{{ template.name }}</span><AcmeBadge v-if="template.savedId" tone="violet" class="ml-auto">{{ t('Yours') }}</AcmeBadge></span>
                        <span class="mt-3 flex-1 text-sm text-muted">{{ template.description }}</span>
                        <span v-if="template.savedId" class="mt-2 text-xs text-muted">{{ [tc(':count other environment|:count other environments', template.environments), tc(':count uptime check|:count uptime checks', template.monitors), tc(':count goal|:count goals', template.goals)].join(' · ') }}</span>
                        <span class="mt-4 flex flex-wrap gap-1.5"><span v-for="key in template.services" :key="key" class="rounded-full border border-line px-2 py-0.5 text-xs text-ink">{{ serviceName(key) }}</span></span>
                    </button>
                </li>
            </ul>
            <ul v-if="data.templates.some((template) => template.savedId)" class="flex flex-wrap gap-2">
                <li v-for="template in data.templates.filter((item) => item.savedId)" :key="template.key">
                    <DeleteDialog :id="`remove-template-${template.savedId}`" :title="t('Remove template')" :description="template.name" :action="`/api/app/projects/templates/${template.savedId}`" :submit-label="t('Remove template')">
                        <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" icon="trash" @click="open">{{ t('Remove template') }}: {{ template.name }}</AcmeBtn></template>
                    </DeleteDialog>
                </li>
            </ul>

            <AcmeCard v-if="chosen" id="template-form" :title="t('Set up :template', { template: chosen.name })" :description="t('The website is created on the server and set up over SSH; pushes to the branch deploy once it’s live.')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="create">
                    <AcmeAlert v-if="error" tone="danger" class="sm:col-span-2">{{ error }}</AcmeAlert>
                    <UiField id="template-name" :label="t('Project name')" :error="errors?.first('name')">
                        <input id="template-name" v-model="form.name" class="ui-input" maxlength="100" autocomplete="off" required :aria-invalid="!!errors?.first('name') || undefined">
                    </UiField>
                    <UiField id="template-domain" :label="t('Domain')" :description="t('Point its DNS at the server; HTTPS is set up automatically.')" :error="errors?.first('domain')">
                        <input id="template-domain" v-model="form.domain" class="ui-input" placeholder="app.example.com" maxlength="255" required :aria-invalid="!!errors?.first('domain') || undefined">
                    </UiField>
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
                    <div class="flex gap-2 sm:col-span-2">
                        <AcmeBtn type="submit" variant="primary" :loading="busy" :disabled="!ready">{{ t('Create project') }}</AcmeBtn>
                        <AcmeBtn @click="form.template = ''">{{ t('Choose another') }}</AcmeBtn>
                    </div>
                </form>
            </AcmeCard>
        </div>
    </div>
</template>
