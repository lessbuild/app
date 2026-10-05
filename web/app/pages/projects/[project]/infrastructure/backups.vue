<script setup lang="ts">
import type { BackupSummary, StoragePresets } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';

/**
 * Backups (the Acme theme's backups page): the last backup, restore and verified restore, the destinations
 * (S3-compatible storage the account owns) with connection checks, and recent backups across its websites.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
type Destination = { id: number; name: string; storageProvider: string; region: string; endpoint: string | null; bucket: string; pathPrefix: string; schedules: number; backups: number; lastError: string | null; lastVerifiedAt: string | null };
type BackupsPage = {
    overview: ProjectOverview;
    accountName: string;
    destinations: Destination[];
    backups: Array<BackupSummary & { websiteDeleted: boolean }>;
    summary: { backup: string | null; restore: string | null; restoreSeconds: number | null; verification: string | null };
    presets: StoragePresets;
    canManage: boolean;
};
const { t, tc, dateTime } = useT();
const labels = useDeployLabels();
const bytes = useFileSize();
const route = useRoute();
const { data } = await useApi<BackupsPage>(() => `/projects/${route.params.project}/infrastructure/backups`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/infrastructure/backups/destinations`);
const tiles = computed(() => [
    { label: t('Last backup'), value: data.value.summary.backup ? dateTime(data.value.summary.backup) : t('None yet'), note: null },
    { label: t('Last restore'), value: data.value.summary.restore ? dateTime(data.value.summary.restore) : t('None yet'), note: data.value.summary.restoreSeconds !== null ? t('Took :duration', { duration: labels.duration(data.value.summary.restoreSeconds) }) : null },
    { label: t('Last verified restore'), value: data.value.summary.verification ? dateTime(data.value.summary.verification) : t('None yet'), note: null },
]);
</script>

<template>
    <div>
        <ProjectHeader
            :overview="data.overview"
            :title="t('Infrastructure')"
            :description="t('Websites are backed up with restic to S3-compatible storage: the database, the .env file and shared storage, encrypted before they leave the server.')"
        >
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ query: { dialog: 'add-backup-destination' } }">{{ t('Add destination') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <div class="grid gap-3 sm:grid-cols-3">
                <div v-for="tile in tiles" :key="tile.label" class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ tile.label }}</p>
                    <p class="mt-2 text-lg font-semibold text-ink">{{ tile.value }}</p>
                    <p v-if="tile.note" class="text-xs text-muted">{{ tile.note }}</p>
                </div>
            </div>

            <AcmeCard :title="t('Destinations')" :description="t('Buckets belong to :account; each website gets its own restic repository under the prefix.', { account: data.accountName })" :padded="false">
                <ul v-if="data.destinations.length > 0" class="divide-y divide-line">
                    <li v-for="destination in data.destinations" :key="destination.id" class="grid gap-2 px-5 py-3.5 sm:px-6">
                        <div class="flex flex-wrap items-center gap-3">
                            <AcmeIconBubble icon="folder" />
                            <span class="min-w-0 flex-1">
                                <b class="block text-sm font-medium text-ink">{{ destination.name }}</b>
                                <span class="text-xs text-muted">
                                    {{ data.presets[destination.storageProvider]?.name ?? destination.storageProvider }} · <span class="font-mono">{{ destination.bucket }}/{{ destination.pathPrefix }}</span>
                                    · {{ tc(':count schedule|:count schedules', destination.schedules, { count: destination.schedules }) }} · {{ tc(':count backup|:count backups', destination.backups, { count: destination.backups }) }}
                                </span>
                            </span>
                            <AcmeBadge v-if="destination.lastError" tone="red" dot>{{ t('Check failed') }}</AcmeBadge>
                            <AcmeBadge v-else-if="destination.lastVerifiedAt" tone="green" dot><Rich :text="t('Works · :when')"><template #when><RelativeTime :at="destination.lastVerifiedAt" /></template></Rich></AcmeBadge>
                            <AcmeBadge v-else dot>{{ t('Not checked') }}</AcmeBadge>
                            <div v-if="data.canManage" class="flex flex-wrap gap-1">
                                <ApiForm :action="`${base}/${destination.id}/check`"><SubmitButton variant="secondary" size="sm">{{ t('Check connection') }}</SubmitButton></ApiForm>
                                <FormDialog :id="`edit-destination-${destination.id}`" :title="t('Edit')" :action="`${base}/${destination.id}`" method="PUT" :submit="t('Save destination')">
                                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" icon="edit" :label="t('Edit')" @click="open" /></template>
                                    <BackupDestinationFields :destination="destination" :presets="data.presets" />
                                </FormDialog>
                                <DeleteDialog
                                    :id="`delete-destination-${destination.id}`"
                                    :title="t('Delete :destination?', { destination: destination.name })"
                                    :description="t('The bucket and anything in it stay as they are.')"
                                    :action="`${base}/${destination.id}`"
                                    :submit-label="t('Delete destination')"
                                >
                                    <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" icon="trash" :label="t('Delete')" @click="open" /></template>
                                </DeleteDialog>
                            </div>
                        </div>
                        <p v-if="destination.lastError" class="text-sm text-rose-600 dark:text-rose-400">{{ destination.lastError }}</p>
                    </li>
                </ul>
                <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No destinations yet.') }}</p>
            </AcmeCard>

            <AcmeCard :title="t('Recent backups')" :description="t('Run, schedule, restore and verify backups on each website’s page.')" :padded="false">
                <p v-if="data.backups.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No backups yet.') }}</p>
                <DataTable v-else :caption="t('Recent backups')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ t('Website') }}</th><th scope="col">{{ t('Started') }}</th><th scope="col">{{ t('Destination') }}</th><th scope="col" class="text-right">{{ t('Size') }}</th><th scope="col">{{ t('Status') }}</th></tr>
                    </template>
                    <tr v-for="backup in data.backups" :key="backup.id">
                        <td>
                            <span v-if="backup.websiteDeleted">{{ backup.website }}</span>
                            <NuxtLink v-else :to="`/projects/${project.id}/infrastructure/websites/${backup.websiteId}?tab=backups`" class="font-medium text-ink hover:underline">{{ backup.website }}</NuxtLink>
                        </td>
                        <td class="text-muted">{{ backup.createdAt ? dateTime(backup.createdAt) : '—' }}</td>
                        <td class="text-muted">{{ backup.destination }}</td>
                        <td class="text-right tabular-nums">{{ bytes(backup.sizeBytes) }}</td>
                        <td><BackupStatusBadge :status="backup.status" /></td>
                    </tr>
                </DataTable>
            </AcmeCard>
        </div>

        <UiDialog v-if="data.canManage" id="add-backup-destination" :title="t('Add a backup destination')" :description="t('S3-compatible storage you own. We check we can write to it before saving.')">
            <ApiForm :action="base" class="grid gap-4">
                <BackupDestinationFields :destination="null" :presets="data.presets" />
                <ul class="grid gap-1 text-xs text-muted">
                    <li v-for="(preset, key) in data.presets" :key="key"><span class="font-medium text-ink">{{ preset.name }}:</span> {{ preset.description }} <span class="font-mono">{{ preset.endpoint }}</span></li>
                </ul>
                <div class="flex justify-end"><SubmitButton>{{ t('Add destination') }}</SubmitButton></div>
            </ApiForm>
        </UiDialog>
    </div>
</template>
