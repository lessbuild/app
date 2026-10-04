<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Addresses blocked for attacking the project's servers, the blocking settings, and recently lifted blocks. */
definePageMeta({ layout: 'app', service: 'security' });
type Block = { id: number; ip: string; reason: string; server: string; detail: string | null; createdAt: string | null; expiresAt: string };
type AttacksPage = { overview: ProjectOverview; active: Block[]; history: Block[]; settings: { autoblock: boolean; blockHours: number; allowlist: string[] }; included: boolean; canManage: boolean };
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<AttacksPage>(() => `/projects/${route.params.project}/security/attacks`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/security/attacks`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Attacks')" :description="t('Addresses blocked for attacking this project’s servers: repeated failed sign-ins, probing for vulnerable paths, or flooding a site.')">
            <template v-if="data.canManage" #actions>
                <UiButton :to="{ query: { dialog: 'blocking-settings' } }"><Icon name="cog" class="h-4 w-4" />{{ t('Blocking settings') }}</UiButton>
            </template>
        </ProjectHeader>
        <PlanNotice v-if="!data.included" :message="t('Automatic blocking comes with the Pro Security plan and above.')" />

        <section class="ui-card p-5 sm:p-6" aria-labelledby="blocking">
            <h2 id="blocking" class="text-lg font-extrabold text-ink">{{ t('Blocking') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ t('Every five minutes, the last ten minutes of each website’s access log are checked. Blocks go into the server’s firewall and lift on their own. Behind Cloudflare’s proxy, block at Cloudflare instead (Firewall).') }}</p>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs font-bold text-muted">{{ t('Automatic blocking') }}</dt><dd class="mt-1 font-semibold text-ink">{{ data.settings.autoblock ? t('On') : t('Off') }}</dd></div>
                <div><dt class="text-xs font-bold text-muted">{{ t('Blocks last') }}</dt><dd class="mt-1 font-semibold text-ink">{{ tc(':count hour|:count hours', data.settings.blockHours, { count: data.settings.blockHours }) }}</dd></div>
                <div><dt class="text-xs font-bold text-muted">{{ t('Never block') }}</dt><dd class="mt-1 font-mono text-xs text-ink">{{ data.settings.allowlist.length ? data.settings.allowlist.join(', ') : t('No exceptions') }}</dd></div>
            </dl>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="active-blocks">
            <h2 id="active-blocks" class="border-b border-line px-5 py-4 text-lg font-extrabold text-ink">{{ t('Blocked now') }}</h2>
            <p v-if="data.active.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No one is blocked right now.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="block in data.active" :key="block.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <div class="min-w-0">
                        <p><span class="font-mono font-bold text-ink">{{ block.ip }}</span> <span class="text-muted">· {{ block.reason }} · {{ block.server }}<template v-if="block.detail"> · {{ block.detail }}</template></span></p>
                        <p class="text-xs text-muted"><Rich :text="t('Until :time')"><template #time><RelativeTime :at="block.expiresAt" /></template></Rich></p>
                    </div>
                    <ApiForm v-if="data.canManage" :action="`${base}/${block.id}`" method="DELETE">
                        <SubmitButton variant="quiet" size="sm">{{ t('Unblock') }}</SubmitButton>
                    </ApiForm>
                </li>
            </ul>
        </section>

        <section v-if="data.history.length > 0" class="ui-card overflow-hidden" aria-labelledby="past-blocks">
            <h2 id="past-blocks" class="border-b border-line px-5 py-4 text-lg font-extrabold text-ink">{{ t('Recently lifted') }}</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="block in data.history" :key="block.id" class="px-5 py-2 text-muted"><span class="font-mono text-ink">{{ block.ip }}</span> · {{ block.reason }}<template v-if="block.createdAt"> · {{ dateTime(block.createdAt) }}</template></li>
            </ul>
        </section>

        <FormDialog v-if="data.canManage" id="blocking-settings" :title="t('Blocking settings')" :action="`${base}/settings`" method="PUT" :submit="t('Save')">
            <CheckboxField id="autoblock" name="autoblock" :label="t('Block attackers automatically')" :checked="data.settings.autoblock" />
            <InputField id="block-hours" name="block_hours" type="number" min="1" max="720" :label="t('Block for (hours)')" :model-value="String(data.settings.blockHours)" required />
            <TextareaField id="allowlist" name="allowlist" :label="t('Never block')" :model-value="data.settings.allowlist.join('\n')" rows="3" :description="t('Addresses or networks, one per line, such as your office or a monitoring service.')" />
        </FormDialog>
    </div>
</template>
