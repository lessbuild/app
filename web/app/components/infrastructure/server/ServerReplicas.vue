<script setup lang="ts">
import type { ReplicaRow, ServerPage } from '~/types/infrastructure';
import type { Tone } from '~/types/ui';

/** A database server's read replicas (or the primary it copies), adding one, and promoting a replica. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, tc } = useT();
const replication = computed(() => props.page.replication);
const server = computed(() => props.page.server);
const statuses = computed<Record<string, { tone: Tone; label: string }>>(() => ({
    setting_up: { tone: 'info', label: t('Copying') },
    streaming: { tone: 'success', label: t('Streaming') },
    broken: { tone: 'danger', label: t('Stopped') },
    failed: { tone: 'danger', label: t('Setup failed') },
}));
const lag = (replica: ReplicaRow) => (replica.lagSeconds === null ? t('Lag unknown') : tc(':count second behind|:count seconds behind', replica.lagSeconds, { count: replica.lagSeconds }));
const readConfig = computed(() => {
    const hosts = (replication.value?.replicas ?? []).map((replica) => `'${replica.address}'`).join(', ');
    return `'read' => ['host' => [${hosts}]],\n'write' => ['host' => ['${server.value.privateIp ?? server.value.ip}']],\n'sticky' => true,`;
});
</script>

<template>
    <div v-if="replication" class="space-y-10">
        <template v-if="replication.primary">
            <AcmeCard
id="replica"
                :padded="false"
                :title="t('Read replica')"
                :description="t('This server copies :primary continuously and serves reads only. Point read-only queries and reports here to take load off the primary.', { primary: replication.primary.name })"
            >
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <AcmeBadge :tone="acmeTone(statuses[replication.primary.self.status ?? '']?.tone ?? 'neutral')">{{ statuses[replication.primary.self.status ?? '']?.label ?? replication.primary.self.status }}</AcmeBadge>
                        <template v-if="replication.primary.self.status === 'streaming'">{{ lag(replication.primary.self) }}</template>
                        <span v-if="replication.primary.self.checkedAt" class="text-muted">· <Rich :text="t('checked :time')"><template #time><RelativeTime :at="replication.primary.self.checkedAt" /></template></Rich></span>
                    </p>
                    <AcmeAlert v-if="replication.primary.self.error" tone="danger">{{ replication.primary.self.error }}</AcmeAlert>
                </div>
            </AcmeCard>
            <AcmeCard
v-if="page.canRunCommands"
                id="promote"
                :padded="false"
                :title="t('Promote to a standalone server')"
                :description="t('Stops following the primary and starts accepting writes, keeping everything copied so far. Use it when the primary has failed, then point your applications here.')"
            >
                <ApiForm :action="`${base}/promote`" class="flex flex-wrap items-end gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                    <InputField name="confirmation" :label="t('Type :name to confirm', { name: server.name })" autocomplete="off" required />
                    <SubmitButton variant="danger">{{ t('Promote') }}</SubmitButton>
                </ApiForm>
            </AcmeCard>
        </template>
        <template v-else>
            <AcmeCard
id="replicas"
                :padded="false"
                :title="t('Read replicas')"
                :description="t('Other database servers in your cloud account that copy this one continuously and serve reads, over the private network when they share one and TLS otherwise.')"
            >
                <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                    <div v-for="replica in replication.replicas" :key="replica.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                        <div class="min-w-0">
                            <NuxtLink :to="`/projects/${page.overview.project.id}/infrastructure/servers/${replica.id}?tab=replicas`" class="text-sm font-bold text-primary hover:underline">{{ replica.name }}</NuxtLink>
                            <p class="text-xs text-muted"><span class="font-mono">{{ replica.address }}</span><template v-if="replica.status === 'streaming'"> · {{ lag(replica) }}</template></p>
                            <p v-if="replica.error" class="text-xs text-danger">{{ replica.error }}</p>
                        </div>
                        <AcmeBadge :tone="acmeTone(statuses[replica.status ?? '']?.tone ?? 'neutral')">{{ statuses[replica.status ?? '']?.label ?? replica.status }}</AcmeBadge>
                    </div>
                    <p v-if="replication.replicas.length === 0" class="text-sm text-muted">{{ t('No read replicas yet.') }}</p>
                    <div v-else class="grid gap-2">
                        <p class="text-sm text-muted">{{ t('In a Laravel app, send reads to the replicas in config/database.php:') }}</p>
                        <CodeBlock :code="readConfig" />
                    </div>
                </div>
            </AcmeCard>
            <AcmeCard
v-if="page.canRunCommands"
                id="add-replica"
                :padded="false"
                :title="t('Add a read replica')"
                :description="t('Choose another :engine server in this account. Its current data is moved aside on the server and replaced by a copy of this one; large databases take a while to copy.', { engine: server.databaseEngine === 'postgres' ? 'PostgreSQL' : 'MySQL' })"
            >
                <div class="px-5 pb-5 sm:px-6 sm:pb-6">
                    <p v-if="replication.candidates.length === 0" class="text-sm text-muted">{{ t('Create another database server with the same engine first, ideally in the same region.') }}</p>
                    <ApiForm v-else :action="`${base}/replicas`" class="flex flex-wrap items-end gap-3">
                        <SelectField name="replica_server_id" :label="t('Replica')" :options="replication.candidates" />
                        <InputField name="confirmation" :label="t('Type the replica’s name to confirm')" autocomplete="off" required />
                        <SubmitButton variant="secondary">{{ t('Add replica') }}</SubmitButton>
                    </ApiForm>
                </div>
            </AcmeCard>
        </template>
    </div>
</template>
