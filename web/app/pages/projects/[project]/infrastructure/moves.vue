<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * Move sites from Laravel Forge or Ploi: read the servers and sites with an API token, then recreate each site on a
 * server here with its environment file, cron jobs and daemons. Nothing changes in the other tool.
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
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Move from Forge or Ploi')"
            :description="t('Read your servers and sites from Laravel Forge or Ploi, then recreate each site on a server here with its environment file, cron jobs and daemons. Nothing changes in the other tool.')"
        />

        <SettingsSection
            :title="t('Connect')"
            :description="t('Create an API token in the other tool (Forge: Account → API tokens; Ploi: Profile → API keys) and paste it here. It’s stored encrypted and only read from.')"
        >
            <ApiForm :action="base" class="grid items-end gap-4 p-4 sm:grid-cols-[12rem_1fr_auto] sm:p-6">
                <SelectField id="move-source" name="source" :label="t('From')" :options="sources" />
                <InputField id="move-token" name="token" type="password" :label="t('API token')" autocomplete="off" required />
                <SubmitButton>{{ t('Read servers and sites') }}</SubmitButton>
            </ApiForm>
        </SettingsSection>

        <SettingsSection v-for="move in data.moves" :key="move.id" :title="t('From :tool', { tool: move.source })" :description="t('Choose a server here for each site and move it; then follow the steps below.')">
            <div class="grid gap-6 p-4 sm:p-6">
                <p v-if="move.fetchedAt" class="text-xs text-muted"><Rich :text="t('Read :time.')"><template #time><RelativeTime :at="move.fetchedAt" /></template></Rich></p>
                <Alert v-if="data.servers.length === 0" tone="info">{{ t('Create or import a server here first; sites move onto it.') }}</Alert>
                <div v-for="source in move.servers" :key="source.name" class="grid gap-3">
                    <h3 class="text-sm font-bold text-ink">{{ source.name }} <span v-if="source.ip" class="font-mono text-xs font-normal text-muted">{{ source.ip }}</span></h3>
                    <div v-for="site in source.sites" :key="site.key" class="grid gap-3 rounded-panel border border-line p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-bold text-ink">{{ site.domain }}</p>
                                <p class="text-xs text-muted">
                                    {{ site.repository ? `${site.repository}${site.branch ? ` @ ${site.branch}` : ''}` : t('No repository') }}<template v-if="site.php"> · {{ site.php }}</template>
                                    · {{ tc(':count cron job|:count cron jobs', site.crons, { count: site.crons }) }} · {{ tc(':count daemon|:count daemons', site.daemons, { count: site.daemons }) }}
                                    · {{ site.hasEnv ? t('environment file read') : t('no environment file') }}
                                </p>
                            </div>
                            <TextLink v-if="site.movedWebsiteId" :to="`/projects/${project.id}/infrastructure/websites/${site.movedWebsiteId}`">{{ t('Moved: open the website') }}</TextLink>
                            <ApiForm v-else-if="data.servers.length > 0" :action="`${base}/${move.id}/sites`" class="flex flex-wrap items-end gap-2">
                                <input type="hidden" name="site" :value="site.key">
                                <SelectField :id="`move-server-${site.key}`" name="server_id" :label="t('Server here')" :options="data.servers" />
                                <SubmitButton variant="secondary">{{ t('Move') }}</SubmitButton>
                            </ApiForm>
                        </div>
                        <Disclosure v-if="site.deployScript !== ''" :title="t('Deploy script in :tool', { tool: move.source })">
                            <CodeBlock :code="site.deployScript" />
                            <p class="mt-2 text-xs text-muted">{{ t('Deploy runs Composer, migrations and caching for Laravel apps itself; add anything else as deploy hooks on the environment.') }}</p>
                        </Disclosure>
                    </div>
                    <p v-if="source.sites.length === 0" class="text-sm text-muted">{{ t('No sites on this server.') }}</p>
                </div>
                <p v-if="move.servers.length === 0" class="text-sm text-muted">{{ t('No servers were found with that token.') }}</p>
                <Disclosure :title="t('After moving a site')" open>
                    <ol class="grid list-decimal gap-2 pl-5 text-sm text-ink">
                        <li>{{ t('Connect its repository and branch in Deploy, and deploy it once to the new website.') }}</li>
                        <li>{{ t('Copy the database: dump it on the old server (mysqldump or pg_dump) and load it on the new one, or restore a backup there.') }}</li>
                        <li>{{ t('Copy uploaded files that aren’t in Git, such as storage/app, with rsync.') }}</li>
                        <li>{{ t('Lower the DNS TTL, then point the domain at the new server. The certificate is issued as soon as it resolves.') }}</li>
                        <li>{{ t('Once traffic has moved, remove the site from :tool.', { tool: move.source }) }}</li>
                    </ol>
                </Disclosure>
                <ApiForm :action="`${base}/${move.id}`" method="DELETE" :confirm="t('Forget the token and what was read')">
                    <SubmitButton variant="secondary" size="sm">{{ t('Forget the token and what was read') }}</SubmitButton>
                </ApiForm>
            </div>
        </SettingsSection>
    </div>
</template>
