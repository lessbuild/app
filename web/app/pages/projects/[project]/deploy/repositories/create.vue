<script setup lang="ts">
import type { RepositoryFormOptions } from '~/types/deploy';
import type { ProjectOverview } from '~/types/projects';

/**
 * Connect a repository (the Acme theme's connect page): pick one the Git provider can reach (or type its address),
 * choose where it deploys, then optional build commands and path filters, with a summary beside them. The GitHub
 * App's list of repositories sends people here with the provider, address and branch in the query.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
type Reachable = { name: string; url: string; private: boolean; branch: string; updatedAt: string | null };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; options: RepositoryFormOptions }>(() => `/projects/${route.params.project}/deploy/repositories/create`);
const project = computed(() => data.value.overview.project);
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : '');
const form = reactive({
    provider: text(route.query.provider_id) || (data.value.options.providers[0]?.value ?? ''),
    url: text(route.query.url),
    branch: text(route.query.branch) || 'main',
    name: '',
    website: data.value.options.websites[0]?.value ?? '',
    environment: '',
});
const search = ref('');
const reachable = ref<Reachable[]>([]);
const loading = ref(false);
const problem = ref<string | null>(null);
const advanced = ref(false);
const shown = computed(() => reachable.value.filter((repository) => repository.name.toLowerCase().includes(search.value.trim().toLowerCase())));
const label = (options: Array<{ value: string; label: string }>, value: string) => options.find((option) => option.value === value)?.label ?? '—';

/** Load the repositories the chosen provider can reach. */
async function load() {
    reachable.value = [];
    problem.value = null;
    if (!form.provider) {
        return;
    }
    loading.value = true;
    const result = await send<{ repositories: Reachable[]; error: string | null }>('GET', `/projects/${project.value.id}/deploy/repositories/reachable?provider=${form.provider}`).catch(() => null);
    reachable.value = result?.repositories ?? [];
    problem.value = result === null ? t('Something went wrong. Try again.') : result.error;
    loading.value = false;
}

/**
 * Choose a repository from the list, filling in its address, branch and a name.
 *
 * @param repository The repository.
 */
function pick(repository: Reachable) {
    form.url = repository.url;
    form.branch = repository.branch;
    form.name ||= repository.name.split('/').pop() ?? repository.name;
}

watch(() => form.provider, load);
onMounted(load);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Connect a repository')" :description="t('It’s cloned over HTTPS with the provider’s token and deployed to the website you choose.')" />
        <ApiForm :action="`/api/app/projects/${project.id}/deploy/repositories`" class="!grid gap-6 @3xl:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <AcmeCard :title="t('1. Choose a repository')" :description="t('Repositories your Git provider lets us reach.')">
                    <div class="grid gap-3 sm:grid-cols-[14rem_1fr] sm:items-end">
                        <SelectField id="repository-provider" v-model="form.provider" name="provider_id" :label="t('Git provider')" :options="data.options.providers" :description="data.options.providers.length === 0 ? t('Add a GitHub, GitLab or Bitbucket token under Account → Providers first.') : undefined" />
                        <AcmeSearchInput v-model="search" :label="t('Search repositories')" />
                    </div>
                    <p v-if="loading" class="mt-4 text-sm text-muted" role="status">{{ t('Loading…') }}</p>
                    <AcmeAlert v-else-if="problem" tone="warning" class="mt-4">{{ problem }}</AcmeAlert>
                    <ul v-else-if="shown.length > 0" class="mt-4 max-h-80 divide-y divide-line overflow-y-auto rounded-xl border border-line" role="radiogroup" :aria-label="t('Repositories')">
                        <li v-for="repository in shown" :key="repository.url">
                            <label :class="['flex cursor-pointer items-center gap-3 px-4 py-3 text-sm', form.url === repository.url && 'bg-accent/[.05]']">
                                <input type="radio" class="accent-[var(--ui-primary)]" :checked="form.url === repository.url" @change="pick(repository)">
                                <AcmeIcon name="branch" :size="15" class="text-muted" />
                                <span class="flex-1 truncate font-mono text-ink">{{ repository.name }}</span>
                                <AcmeBadge :tone="repository.private ? 'gray' : 'blue'">{{ repository.private ? t('private') : t('public') }}</AcmeBadge>
                                <RelativeTime v-if="repository.updatedAt" :at="repository.updatedAt" class="hidden text-xs text-muted sm:block" />
                            </label>
                        </li>
                    </ul>
                    <p v-else-if="reachable.length > 0" class="mt-4 text-sm text-muted">{{ t('No repositories match “:query”.', { query: search }) }}</p>
                    <div class="mt-4">
                        <InputField id="repository-url" v-model="form.url" name="url" :label="t('Repository')" placeholder="github.com/acme/shop" maxlength="255" :description="t('Chosen from the list above, or type the address of one that isn’t there.')" required />
                    </div>
                </AcmeCard>

                <AcmeCard :title="t('2. Where it deploys')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <InputField id="repository-name" v-model="form.name" name="name" :label="t('Name')" placeholder="storefront-web" maxlength="120" required />
                        <InputField id="repository-branch" v-model="form.branch" name="branch" :label="t('Branch')" maxlength="255" required />
                        <SelectField id="repository-website" v-model="form.website" name="website_id" :label="t('Deploys to website')" :options="data.options.websites" />
                        <SelectField id="repository-environment" v-model="form.environment" name="environment_id" :label="t('Environment')" :placeholder="t('None')" :options="data.options.environments" />
                    </div>
                    <p class="mt-3 text-xs text-muted">{{ t('Deploys show up in the environment’s Monitoring releases.') }}</p>
                </AcmeCard>

                <AcmeCard>
                    <button type="button" class="flex w-full items-center justify-between font-semibold text-ink" :aria-expanded="advanced" @click="advanced = !advanced">
                        {{ t('3. Build and filters') }} <span class="flex items-center gap-2 text-sm font-normal text-muted">{{ t('Optional') }}<AcmeIcon name="chevronDown" :size="16" :class="advanced && 'rotate-180'" /></span>
                    </button>
                    <div v-show="advanced" class="mt-5 grid gap-4 sm:grid-cols-2">
                        <InputField id="repository-root" name="deployment_root" :label="t('Subdirectory (optional)')" placeholder="apps/web" maxlength="512" class="sm:col-span-2" />
                        <TextareaField id="repository-build" name="build_commands" :label="t('Build commands')" rows="3" :description="t('Run in the new release before it goes live.')" />
                        <TextareaField id="repository-after" name="post_deployment_commands" :label="t('After-deploy commands')" rows="3" :description="t('Run once the release is live.')" />
                        <TextareaField id="repository-include" name="auto_deploy_include_paths" :label="t('Push deploys only for these paths')" rows="2" :description="t('One glob per line, e.g. app/**; leave empty for any change.')" />
                        <TextareaField id="repository-exclude" name="auto_deploy_exclude_paths" :label="t('Ignore pushes that only change')" rows="2" :description="t('One glob per line, e.g. docs/** or *.md.')" />
                    </div>
                </AcmeCard>
            </div>
            <aside class="space-y-4 @3xl:sticky @3xl:top-4 @3xl:self-start">
                <AcmeCard :title="t('Summary')">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Repository') }}</dt><dd class="truncate font-mono text-ink">{{ form.url || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Branch') }}</dt><dd class="text-ink">{{ form.branch || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Website') }}</dt><dd class="truncate text-ink">{{ label(data.options.websites, form.website) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Environment') }}</dt><dd class="text-ink">{{ form.environment ? label(data.options.environments, form.environment) : t('None') }}</dd></div>
                    </dl>
                    <SubmitButton class="mt-5 w-full justify-center">{{ t('Connect repository') }}</SubmitButton>
                    <CancelButton :to="`/projects/${project.id}/deploy`" class="mt-2 w-full" />
                </AcmeCard>
            </aside>
        </ApiForm>
    </div>
</template>
