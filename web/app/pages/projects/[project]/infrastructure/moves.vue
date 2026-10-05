<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * Move sites from Laravel Forge or Ploi (the Acme theme's moves page): read the servers and sites with an API token,
 * then recreate each site on a server here with its environment file, cron jobs and daemons, and tick off the steps
 * that follow. Nothing changes in the other tool.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
type Site = { key: string; domain: string; repository: string | null; branch: string | null; php: string | null; crons: number; daemons: number; hasEnv: boolean; deployScript: string; movedWebsiteId: number | null };
type Move = { id: number; source: string; fetchedAt: string | null; servers: Array<{ name: string; ip: string | null; sites: Site[] }> };
type MovesPage = { overview: ProjectOverview; sources: Record<string, string>; moves: Move[]; servers: Option[] };
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<MovesPage>(() => `/projects/${route.params.project}/infrastructure/moves`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/moves`);
const sources = computed(() => Object.entries(data.value.sources).map(([value, label]) => ({ value, label })));
const source = ref<string | number>(sources.value[0]?.value ?? '');
const sourceName = computed(() => data.value.sources[String(source.value)] ?? '');
const steps = (tool: string) => [
    t('Connect its repository and branch in Deploy, and deploy it once to the new website.'),
    t('Copy the database: dump it on the old server (mysqldump or pg_dump) and load it on the new one, or restore a backup there.'),
    t('Copy uploaded files that aren’t in Git, such as storage/app, with rsync.'),
    t('Lower the DNS TTL, then point the domain at the new server. The certificate is issued as soon as it resolves.'),
    t('Once traffic has moved, remove the site from :tool.', { tool }),
];
// Which follow-up steps are done, per move, kept in this browser.
const done = ref<Record<number, number[]>>({});
onMounted(() => {
    try {
        done.value = JSON.parse(localStorage.getItem('buildpusher.moves.done') ?? '{}') as Record<number, number[]>;
    } catch {
        done.value = {};
    }
});

/**
 * Tick a follow-up step off, or untick it.
 *
 * @param move The move.
 * @param step The step's position.
 * @param checked Whether it's done.
 */
function tick(move: number, step: number, checked: boolean) {
    const steps = done.value[move] ?? [];
    done.value = { ...done.value, [move]: checked ? [...steps, step] : steps.filter((item) => item !== step) };
    try {
        localStorage.setItem('buildpusher.moves.done', JSON.stringify(done.value));
    } catch {
        // Private browsing without storage: the ticks last until the page is left.
    }
}
</script>

<template>
    <div>
        <ProjectHeader
            :overview="data.overview"
            :title="t('Move from Forge or Ploi')"
            :description="t('Read your servers and sites from Laravel Forge or Ploi, then recreate each site on a server here with its environment file, cron jobs and daemons. Nothing changes in the other tool.')"
        />

        <div class="space-y-6">
            <AcmeCard :title="data.moves.length === 0 ? t('Connect') : t('Connect another tool')">
                <AcmeSegmented v-if="sources.length > 1" v-model="source" :label="t('From')" :options="sources" />
                <p class="mt-4 text-sm text-muted">{{ t('Create an API token in the other tool (Forge: Account → API tokens; Ploi: Profile → API keys) and paste it here. It’s stored encrypted and only read from.') }}</p>
                <ApiForm :action="base" class="mt-4 flex flex-wrap items-end gap-2">
                    <input type="hidden" name="source" :value="source">
                    <InputField id="move-token" name="token" type="password" :label="t('API token for :tool', { tool: sourceName })" autocomplete="off" class="min-w-60 flex-1" required />
                    <SubmitButton>{{ t('Read servers and sites') }}</SubmitButton>
                </ApiForm>
            </AcmeCard>

            <template v-for="move in data.moves" :key="move.id">
                <AcmeAlert tone="success" :title="t('Connected to :tool', { tool: move.source })">
                    <template v-if="move.fetchedAt"><Rich :text="t('Read :time.')"><template #time><RelativeTime :at="move.fetchedAt" /></template></Rich> </template>
                    {{ t('Choose a server here for each site and move it; then follow the steps below.') }}
                </AcmeAlert>
                <AcmeAlert v-if="data.servers.length === 0" tone="info">{{ t('Create or import a server here first; sites move onto it.') }}</AcmeAlert>
                <AcmeCard v-for="server in move.servers" :key="`${move.id}-${server.name}`" :title="server.name" :description="server.ip ?? undefined" :padded="false">
                    <ul v-if="server.sites.length > 0" class="divide-y divide-line">
                        <li v-for="site in server.sites" :key="site.key" class="grid gap-3 px-5 py-3.5 sm:px-6">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="min-w-0 flex-1">
                                    <b class="block text-sm font-medium text-ink">{{ site.domain }}</b>
                                    <span class="text-xs text-muted">
                                        {{ site.repository ? `${site.repository}${site.branch ? ` @ ${site.branch}` : ''}` : t('No repository') }}<template v-if="site.php"> · {{ site.php }}</template>
                                        · {{ tc(':count cron job|:count cron jobs', site.crons, { count: site.crons }) }} · {{ tc(':count daemon|:count daemons', site.daemons, { count: site.daemons }) }}
                                        · {{ site.hasEnv ? t('environment file read') : t('no environment file') }}
                                    </span>
                                </span>
                                <template v-if="site.movedWebsiteId">
                                    <AcmeBadge tone="green" dot>{{ t('Moved') }}</AcmeBadge>
                                    <AcmeBtn size="sm" :to="`/projects/${project.id}/infrastructure/websites/${site.movedWebsiteId}`">{{ t('Open website') }}</AcmeBtn>
                                </template>
                                <ApiForm v-else-if="data.servers.length > 0" :action="`${base}/${move.id}/sites`" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="site" :value="site.key">
                                    <SelectField :id="`move-server-${site.key}`" name="server_id" :label="t('Server here')" :options="data.servers" class="w-44" />
                                    <SubmitButton size="sm">{{ t('Move') }}</SubmitButton>
                                </ApiForm>
                            </div>
                            <Disclosure v-if="site.deployScript !== ''" :title="t('Deploy script in :tool', { tool: move.source })">
                                <CodeBlock :code="site.deployScript" />
                                <p class="mt-2 text-xs text-muted">{{ t('Deploy runs Composer, migrations and caching for Laravel apps itself; add anything else as deploy hooks on the environment.') }}</p>
                            </Disclosure>
                        </li>
                    </ul>
                    <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No sites on this server.') }}</p>
                </AcmeCard>
                <p v-if="move.servers.length === 0" class="text-sm text-muted">{{ t('No servers were found with that token.') }}</p>
                <AcmeCard :title="t('After moving a site')" :description="t(':done of :total done', { done: (done[move.id] ?? []).length, total: steps(move.source).length })">
                    <template #action>
                        <ApiForm :action="`${base}/${move.id}`" method="DELETE" :confirm="t('Forget the token and what was read')">
                            <SubmitButton variant="quiet" size="sm">{{ t('Forget the token') }}</SubmitButton>
                        </ApiForm>
                    </template>
                    <ol class="space-y-2">
                        <li v-for="(step, index) in steps(move.source)" :key="index">
                            <label class="flex cursor-pointer items-start gap-3 text-sm text-ink">
                                <input type="checkbox" class="mt-0.5 size-4 accent-[var(--ui-primary)]" :checked="(done[move.id] ?? []).includes(index)" @change="tick(move.id, index, ($event.target as HTMLInputElement).checked)">
                                <span :class="(done[move.id] ?? []).includes(index) && 'text-muted line-through'">{{ step }}</span>
                            </label>
                        </li>
                    </ol>
                </AcmeCard>
            </template>
        </div>
    </div>
</template>
