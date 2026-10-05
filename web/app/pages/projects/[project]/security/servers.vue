<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** The project's servers: open hardening findings, the weekly update window and who has SSH access with their own keys. */
definePageMeta({ layout: 'app', service: 'security' });
type Grant = { id: number; userId: string; name: string; status: 'installing' | 'active' | 'removing' | 'failed'; error: string | null; hasKeys: boolean };
type SecurityServer = {
    id: number;
    name: string;
    user: string;
    ip: string | null;
    region: string | null;
    openFindings: number;
    serious: boolean;
    patchDay: number | null;
    patchHour: number | null;
    patchReboot: boolean;
    lastPatchedAt: string | null;
    lastPatchError: string | null;
    grants: Grant[];
};
type ServersPage = { overview: ProjectOverview; servers: SecurityServer[]; members: Array<{ id: string; name: string; hasKeys: boolean }>; included: boolean; canManage: boolean };
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<ServersPage>(() => `/projects/${route.params.project}/security/servers`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/security/servers`);
const days = computed(() => [
    { value: '1', label: t('Monday') }, { value: '2', label: t('Tuesday') }, { value: '3', label: t('Wednesday') }, { value: '4', label: t('Thursday') },
    { value: '5', label: t('Friday') }, { value: '6', label: t('Saturday') }, { value: '0', label: t('Sunday') },
]);
const dayOptions = computed(() => [{ value: '', label: t('Off') }, ...days.value.map((day) => ({ value: day.value, label: t('Every :day', { day: day.label }) }))]);
const hourOptions = Array.from({ length: 24 }, (_, hour) => ({ value: String(hour), label: `${String(hour).padStart(2, '0')}:00` }));
/** Say when a server installs its updates. */
function windowLabel(server: SecurityServer): string {
    if (server.patchDay === null) {
        return t('No update window.');
    }
    const day = days.value.find((item) => item.value === String(server.patchDay))?.label ?? '';
    const hour = String(server.patchHour ?? 3).padStart(2, '0');
    return server.patchReboot
        ? t('Updates install every :day at :hour:00 UTC, rebooting when needed.', { day, hour })
        : t('Updates install every :day at :hour:00 UTC.', { day, hour });
}
/** The members who don't have access to the server yet, as choices. */
const candidates = (server: SecurityServer) => data.value.members
    .filter((member) => !server.grants.some((grant) => grant.userId === member.id))
    .map((member) => ({ value: member.id, label: member.hasKeys ? member.name : t(':name (no keys yet)', { name: member.name }) }));
const grantLabels = computed(() => ({ installing: t('Installing'), removing: t('Removing'), failed: t('Failed') }));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('The servers this project runs on: how well they’re hardened, and when they install security updates.')" />
        <PlanNotice v-if="!data.included" :message="t('Server hardening and update windows come with the Pro Security plan and above.')" />

        <EmptyState v-if="data.servers.length === 0" icon="server" :title="t('No servers yet')" :description="t('Servers show up here once websites in this project run on them.')" />
        <section v-for="server in data.servers" :key="server.id" class="ui-card grid gap-4 p-5 sm:p-6" :aria-labelledby="`server-${server.id}`">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 :id="`server-${server.id}`" class="text-lg font-semibold text-ink">{{ server.name }}</h2>
                    <p class="text-sm text-muted">{{ [server.ip, server.region].filter(Boolean).join(' · ') }}</p>
                </div>
                <AcmeBadge v-if="server.openFindings === 0" tone="green">{{ t('No open findings') }}</AcmeBadge>
                <NuxtLink v-else :to="`/projects/${project.id}/security/findings?source=servers`">
                    <AcmeBadge :tone="acmeTone(server.serious ? 'danger' : 'warning')">{{ tc(':count open finding|:count open findings', server.openFindings, { count: server.openFindings }) }}</AcmeBadge>
                </NuxtLink>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted">
                    {{ windowLabel(server) }}
                    <Rich v-if="server.lastPatchedAt" :text="t('Last run :time.')"><template #time><RelativeTime :at="server.lastPatchedAt" /></template></Rich>
                </p>
                <div v-if="data.canManage && data.included" class="flex flex-wrap gap-1">
                    <FormDialog :id="`patch-${server.id}`" :title="t('Update window for :server', { server: server.name })" :description="t('Security updates install once a week at this time. Times are in UTC.')" :action="`${base}/${server.id}/patch`" method="PUT" :submit="t('Save')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Change window') }}</AcmeBtn></template>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <SelectField :id="`patch-${server.id}-day`" name="patch_day" :label="t('Update window')" :options="dayOptions" :model-value="server.patchDay === null ? '' : String(server.patchDay)" />
                            <SelectField :id="`patch-${server.id}-hour`" name="patch_hour" :label="t('At (UTC)')" :options="hourOptions" :model-value="String(server.patchHour ?? 3)" />
                        </div>
                        <CheckboxField :id="`patch-${server.id}-reboot`" name="patch_reboot" :label="t('Reboot if needed')" :checked="server.patchReboot" />
                    </FormDialog>
                    <FormDialog :id="`patch-now-${server.id}`" :title="t('Install updates now?')" :description="t('Installs the waiting updates on :server now. Services may restart briefly.', { server: server.name })" :action="`${base}/${server.id}/patch`" method="PUT" :submit="t('Install updates')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Install updates now') }}</AcmeBtn></template>
                        <input type="hidden" name="now" value="1">
                        <input v-if="server.patchDay !== null" type="hidden" name="patch_day" :value="server.patchDay">
                        <input type="hidden" name="patch_hour" :value="server.patchHour ?? 3">
                        <input v-if="server.patchReboot" type="hidden" name="patch_reboot" value="1">
                    </FormDialog>
                </div>
            </div>
            <AcmeAlert v-if="server.lastPatchError" tone="danger">{{ t('The last update failed: :error', { error: server.lastPatchError }) }}</AcmeAlert>

            <div class="grid gap-2 border-t border-line pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-ink">{{ t('SSH access') }} <span class="font-normal text-muted">· {{ t('as :user, with each person’s own keys', { user: server.user }) }}</span></h3>
                    <FormDialog
                        v-if="data.canManage && data.included && candidates(server).length > 0"
                        :id="`ssh-${server.id}`"
                        :title="t('Give SSH access to :server', { server: server.name })"
                        :description="t('The SSH keys on their profile are installed on the server. Leaving the account takes the access away.')"
                        :action="`${base}/${server.id}/ssh`"
                        :submit="t('Give access')"
                    >
                        <template #trigger="{ open }"><AcmeBtn variant="secondary" size="sm" icon="plus" @click="open">{{ t('Give access') }}</AcmeBtn></template>
                        <SelectField :id="`ssh-${server.id}-user`" name="user_id" :label="t('Person')" :options="candidates(server)" />
                    </FormDialog>
                </div>
                <p v-if="server.grants.length === 0" class="text-sm text-muted">{{ t('No one has personal SSH access.') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="grant in server.grants" :key="grant.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ink">{{ grant.name }}</span>
                            <AcmeBadge v-if="grant.status !== 'active'" :tone="acmeTone(grant.status === 'failed' ? 'danger' : 'info')">{{ grantLabels[grant.status] }}</AcmeBadge>
                            <span v-if="grant.status === 'failed' && grant.error" class="text-xs text-danger">{{ grant.error }}</span>
                            <span v-if="!grant.hasKeys" class="text-xs text-muted">· {{ t('no SSH keys on their profile yet') }}</span>
                        </span>
                        <DeleteDialog
                            v-if="data.canManage && grant.status !== 'removing'"
                            :id="`ssh-remove-${grant.id}`"
                            :title="t('Remove :name’s access?', { name: grant.name })"
                            :description="t('Their keys are taken off :server.', { server: server.name })"
                            :action="`${base}/${server.id}/ssh/${grant.id}`"
                            :submit-label="t('Remove access')"
                        >
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove access') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
