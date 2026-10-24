<script setup lang="ts">
import type { LaunchFormPage, LaunchPlan } from '~/types/deploy';
import { ValidationError } from '~/utils/client';

/**
 * Launch from a repository: pick the repository from the provider's list and its branch, and a website you've already
 * added under Infrastructure to build the plan around. Buildpusher reads the repository and proposes the set-up (an
 * environment per app, database, workers, Redis and the secrets still needed, and a server with room for it). Each app
 * goes onto one of your websites (add more under Infrastructure). Launch sets it all up and deploys.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<LaunchFormPage>(() => `/projects/${route.params.project}/deploy/launch`);
const project = computed(() => data.value.overview.project.id);
const source = ref<string | null>(data.value.sources[0]?.value ?? null);
const url = ref<string | null>('');
const branch = ref<string | null>('main');
const seed = ref<string>(data.value.websites[0]?.value ?? '');
const plan = ref<LaunchPlan | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const reachable = ref<Array<{ name: string; url: string; branch: string }>>([]);
const typeUrl = ref(false);
// Websites an app can go onto — added under Infrastructure, not here.
const hasWebsites = computed(() => data.value.websites.length > 0);
const websiteOptions = computed(() => data.value.websites.map((website) => ({ value: website.value, label: website.label })));
const addWebsiteLink = computed(() => `/projects/${project.value}/infrastructure/websites/create`);
const repoOptions = computed(() => reachable.value.map((repository) => ({ value: repository.url, label: repository.name })));
// The domain that seeds the plan: the website chosen to build it around.
const seedDomain = computed(() => data.value.websites.find((website) => website.value === seed.value)?.domain ?? '');
// Launch is only ready once every app has a website chosen.
const appsNeedWebsite = computed(() => (plan.value?.apps ?? []).some((app) => !app.websiteId));
const server = reactive({ mode: 'existing', serverId: '', cloud: data.value.clouds[0]?.value ?? '', region: '', size: '', image: '' });
const catalog = ref<{ regions: Array<{ id: string; label: string }>; sizes: Array<{ id: string; label: string }>; images: Array<{ id: string; label: string }> } | null>(null);
const github = computed(() => data.value.sources.find((option) => option.value === source.value)?.label.includes('GitHub') ?? false);
const choice = (items: Array<{ id: string; label: string }>) => items.map((item) => ({ value: item.id, label: item.label }));
const statuses = computed<Record<string, string>>(() => ({ queued: t('Starting'), running: t('Running'), waiting: t('Running'), done: t('Done'), failed: t('Stopped') }));

// The source's repositories, to pick from the list (or type one).
watch(source, async (provider) => {
    reachable.value = [];
    if (provider) {
        const result = await send<{ repositories: Array<{ name: string; url: string; branch: string }> }>('GET', `/projects/${project.value}/deploy/repositories/reachable?provider=${provider}`).catch(() => null);
        reachable.value = result?.repositories ?? [];
        typeUrl.value = reachable.value.length === 0;
        if (!typeUrl.value && !reachable.value.some((repository) => repository.url === url.value)) {
            url.value = reachable.value[0]?.url ?? '';
        }
    }
}, { immediate: import.meta.client });
watch(url, (value) => {
    const known = reachable.value.find((repository) => repository.url === value);
    if (known) {
        branch.value = known.branch;
    }
});
// A new server's regions, sizes and images come from its cloud provider.
watch(() => [server.mode, server.cloud], async () => {
    if (server.mode !== 'new' || !server.cloud) {
        return;
    }
    const form = await send<{ catalog: typeof catalog.value }>('GET', `/projects/${project.value}/infrastructure/servers/create?provider=${server.cloud}`).catch(() => null);
    catalog.value = form?.catalog ?? null;
    server.region = catalog.value?.regions[0]?.id ?? '';
    server.image = catalog.value?.images.find((image) => image.label.includes('24.04'))?.id ?? catalog.value?.images[0]?.id ?? '';
    await read();
});

/** Read the repository and propose a plan (again, when the domain, source or new server's provider changes). */
async function read() {
    if (!source.value || !url.value || !branch.value || !seedDomain.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ plan: LaunchPlan }>('POST', `/projects/${project.value}/deploy/launch/plan`, {
            provider_id: Number(source.value), url: url.value, branch: branch.value, domain: seedDomain.value,
            cloud_id: server.mode === 'new' && server.cloud ? Number(server.cloud) : null, region: server.mode === 'new' ? server.region || null : null,
        });
        plan.value = result.plan;
        // Default each app onto a distinct website: the one whose domain matches its proposed one, else the next not
        // already taken by an earlier app, so when there are enough websites nothing is left unselected.
        const taken = new Set<string>();
        plan.value.apps.forEach((app) => {
            const match = data.value.websites.find((website) => website.domain === app.domain && !taken.has(website.value)) ?? data.value.websites.find((website) => !taken.has(website.value));
            app.websiteId = match?.value ?? '';
            if (match) {
                taken.add(match.value);
            }
        });
        server.serverId = server.serverId || String(result.plan.servers.find((option) => option.fits)?.id ?? result.plan.servers[0]?.id ?? '');
        server.mode = result.plan.servers.length === 0 ? 'new' : server.mode;
        server.size = result.plan.recommendedSize?.id ?? server.size;
    } catch (exception) {
        error.value = exception instanceof ValidationError ? (Object.values(exception.errors)[0]?.[0] ?? exception.message) : t('The repository couldn’t be read.');
    }
    busy.value = false;
}

/** Start the launch as planned and chosen, then follow it on its own page. */
async function launch() {
    if (!plan.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const result = await send<{ redirect: string }>('POST', `/projects/${project.value}/deploy/launches`, {
            provider_id: Number(source.value), url: url.value, branch: branch.value, domain: seedDomain.value,
            server: server.mode === 'existing' ? { mode: 'existing', server_id: Number(server.serverId) } : { mode: 'new', provider_id: Number(server.cloud), region: server.region, size: server.size, image: server.image },
            apps: plan.value.apps.map((app) => ({
                name: app.name, website_id: app.websiteId ? Number(app.websiteId) : null,
                environment: app.environment, mysql: app.mysql, redis: app.redis, queue: app.queue, scheduler: app.scheduler,
                githubActions: app.githubActions ?? false, secretValues: app.secretValues ?? {},
            })),
        });
        await navigateTo(result.redirect);
    } catch (exception) {
        error.value = exception instanceof ValidationError ? (Object.values(exception.errors)[0]?.[0] ?? exception.message) : t('The launch couldn’t start.');
    }
    busy.value = false;
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Launch from a repository')" :description="t('Buildpusher reads the repository and proposes the whole set-up: websites, environments, database, workers, production settings and a server with room for it. Change anything, then launch.')" />
        <div class="grid gap-6">
            <AcmeAlert v-if="data.sources.length === 0" tone="info">{{ t('Connect GitHub, GitLab or Bitbucket under Account → Providers first.') }}</AcmeAlert>
            <AcmeAlert v-else-if="!hasWebsites" tone="info">
                {{ t('Add a website first, then launch a repository onto it.') }} <NuxtLink :to="addWebsiteLink" class="font-medium text-ink underline">{{ t('Add a website') }}</NuxtLink>
            </AcmeAlert>
            <AcmeCard v-if="hasWebsites" :title="t('Repository')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="read">
                    <SelectField id="launch-source" v-model="source" name="provider_id" :label="t('Git provider')" :options="data.sources" required />
                    <div class="min-w-0">
                        <SelectField v-if="repoOptions.length > 0 && !typeUrl" id="launch-repo" v-model="url" name="url" :label="t('Repository')" :options="repoOptions" required />
                        <InputField v-else id="launch-url" v-model="url" name="url" :label="t('Repository')" placeholder="github.com/acme/shop" required />
                        <button v-if="repoOptions.length > 0" type="button" class="mt-1 text-xs font-medium text-accent hover:underline" @click="typeUrl = !typeUrl">{{ typeUrl ? t('Pick from the list') : t('Enter an address instead') }}</button>
                    </div>
                    <InputField id="launch-branch" v-model="branch" name="branch" :label="t('Branch')" required />
                    <div class="min-w-0">
                        <SelectField id="launch-seed" v-model="seed" name="seed" :label="t('Website')" :options="websiteOptions" :description="t('Which of your websites to build the plan around. Each app’s website can be changed below.')" required />
                        <NuxtLink :to="addWebsiteLink" class="mt-1 inline-block text-xs font-medium text-accent hover:underline">{{ t('Add a website') }}</NuxtLink>
                    </div>
                    <div class="sm:col-span-2"><UiButton type="submit" variant="primary" :disabled="busy">{{ plan ? t('Read it again') : t('Read the repository') }}</UiButton></div>
                </form>
            </AcmeCard>

            <AcmeAlert v-if="error" tone="danger" role="alert">{{ error }}</AcmeAlert>
            <p v-if="busy" class="text-sm text-muted" role="status">{{ t('Reading the repository…') }}</p>

            <template v-if="plan">
                <AcmeCard v-for="app in plan.apps" :key="app.path" :title="app.label" :description="app.path === '' ? t('At the top of the repository') : t('In :folder/', { folder: app.path })">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <InputField :id="`launch-${app.path}-name`" v-model="app.name" name="name" :label="t('Name')" />
                        <div class="min-w-0">
                            <SelectField :id="`launch-${app.path}-website`" v-model="app.websiteId" name="website_id" :label="t('Website')" :options="websiteOptions" :placeholder="t('Choose a website')" :description="t('The website this app deploys onto.')" required />
                            <NuxtLink :to="addWebsiteLink" class="mt-1 inline-block text-xs font-medium text-accent hover:underline">{{ t('Add a website') }}</NuxtLink>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <ToggleField v-if="app.canUseMysql" :id="`launch-${app.path}-mysql`" name="mysql" :label="t('Use MySQL')" :checked="app.mysql" :description="t('Its own database on the server, connected through a managed resource.')" @toggle="app.mysql = $event" />
                        <ToggleField v-if="app.workerCommand" :id="`launch-${app.path}-queue`" name="queue" :label="t('Queue worker')" :checked="app.queue" :description="app.workerCommand" @toggle="app.queue = $event" />
                        <ToggleField v-if="app.framework === 'laravel'" :id="`launch-${app.path}-scheduler`" name="scheduler" :label="t('Scheduler')" :checked="app.scheduler" description="php artisan schedule:work" @toggle="app.scheduler = $event" />
                        <ToggleField :id="`launch-${app.path}-redis`" name="redis" :label="t('Redis')" :checked="app.redis" :description="t('Runs on the server; cache and sessions use it.')" @toggle="app.redis = $event" />
                        <ToggleField v-if="github && ['php', 'node'].includes(app.runtime)" :id="`launch-${app.path}-actions`" name="githubActions" :label="t('Build on GitHub Actions')" :checked="app.githubActions ?? false" :description="t('For a small server: GitHub builds it and the server only unpacks the result.')" @toggle="app.githubActions = $event" />
                    </div>
                    <div v-if="app.secrets.length > 0" class="mt-4 grid gap-3">
                        <p class="text-sm font-bold text-ink">{{ t('Secrets its .env.example leaves empty') }}</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div v-for="key in app.secrets" :key="key">
                                <label :for="`launch-${app.path}-${key}`" class="mb-1 block font-mono text-xs text-muted">{{ key }}</label>
                                <input :id="`launch-${app.path}-${key}`" v-model="(app.secretValues ??= {})[key]" type="password" autocomplete="off" class="ui-input w-full" :placeholder="t('Optional; add it later under Variables')">
                            </div>
                        </div>
                    </div>
                    <dl class="mt-4 grid gap-1 text-xs text-muted">
                        <div v-if="app.buildCommand"><dt class="inline">{{ t('Build') }}:</dt> <dd class="inline font-mono">{{ app.buildCommand }}</dd></div>
                        <div v-if="app.startCommand"><dt class="inline">{{ t('Start') }}:</dt> <dd class="inline font-mono">{{ app.startCommand }}</dd></div>
                        <div v-if="Object.keys(app.variables).length > 0"><dt class="inline">{{ t('Production settings') }}:</dt> <dd class="inline font-mono">{{ Object.keys(app.variables).join(', ') }}</dd></div>
                    </dl>
                    <p v-for="note in app.notes" :key="note" class="mt-2 text-sm text-muted">{{ note }}</p>
                </AcmeCard>

                <AcmeCard :title="t('Server')" :description="t('What it runs needs about :size GB of memory.', { size: (plan.memoryNeededMb / 1024).toFixed(1) })">
                    <AcmeSegmented
                        v-model="server.mode"
                        :options="[{ value: 'existing', label: t('One of your servers') }, { value: 'new', label: t('A new server') }]"
                        :label="t('Server')"
                    />
                    <div v-if="server.mode === 'existing'" class="mt-4 grid gap-2">
                        <p v-if="plan.servers.length === 0" class="text-sm text-muted">{{ t('No active app server yet. Choose A new server.') }}</p>
                        <label v-for="option in plan.servers" :key="option.id" class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-line p-3 text-sm">
                            <span class="flex items-center gap-3"><input v-model="server.serverId" type="radio" name="server_id" :value="String(option.id)"> <span class="font-bold text-ink">{{ option.label }}</span> <span class="text-muted">{{ option.ip }}</span></span>
                            <AcmeBadge :tone="option.fits ? 'green' : 'amber'" dot>{{ option.memoryGb === null ? t('Size unknown') : option.fits ? t(':have GB, fits', { have: option.memoryGb }) : t(':have GB; needs about :need GB', { have: option.memoryGb, need: option.neededGb }) }}</AcmeBadge>
                        </label>
                    </div>
                    <div v-else class="mt-4 grid gap-4 sm:grid-cols-2">
                        <SelectField id="launch-cloud" v-model="server.cloud" name="cloud" :label="t('Cloud provider')" :options="data.clouds" required />
                        <SelectField v-if="catalog" id="launch-region" v-model="server.region" name="region" :label="t('Region')" :options="choice(catalog.regions)" required @change="read" />
                        <SelectField v-if="catalog" id="launch-size" v-model="server.size" name="size" :label="t('Size')" :options="choice(catalog.sizes)" :description="plan.recommendedSize ? t(':size fits (:memory GB), for :price a month.', { size: plan.recommendedSize.id, memory: plan.recommendedSize.memoryGb, price: plan.recommendedSize.price ?? '—' }) : undefined" required />
                        <SelectField v-if="catalog" id="launch-image" v-model="server.image" name="image" :label="t('Ubuntu image')" :options="choice(catalog.images)" required />
                        <p v-if="data.clouds.length === 0" class="text-sm text-muted sm:col-span-2">{{ t('Connect a cloud provider under Account → Providers first.') }}</p>
                    </div>
                </AcmeCard>

                <div class="flex flex-wrap items-center gap-3">
                    <UiButton variant="primary" size="lg" :disabled="busy || appsNeedWebsite || (server.mode === 'existing' ? !server.serverId : !server.size)" @click="launch">{{ t('Launch') }}</UiButton>
                    <p v-if="appsNeedWebsite" class="text-sm text-muted">
                        {{ t('Choose a website for each app above.') }} <NuxtLink :to="addWebsiteLink" class="font-medium text-ink underline">{{ t('Add a website') }}</NuxtLink>
                    </p>
                    <p v-else class="text-sm text-muted">{{ t('Sets everything up in order, then deploys the API first and the rest after it.') }}</p>
                </div>
            </template>

            <AcmeCard v-if="data.launches.length > 0" :title="t('Recent launches')" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="item in data.launches" :key="item.id" class="flex items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                        <NuxtLink :to="`/projects/${project}/deploy/launches/${item.id}`" class="font-medium text-ink underline">{{ item.repository }}</NuxtLink>
                        <span class="text-muted">{{ statuses[item.status] ?? item.status }}<template v-if="item.createdAt"> · <RelativeTime :at="item.createdAt" /></template></span>
                    </li>
                </ul>
            </AcmeCard>
        </div>
    </div>
</template>
