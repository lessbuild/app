<script setup lang="ts">
import type { BackupSummary, StoragePresets } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';

/** Backup destinations (S3-compatible storage the account owns) and recent backups across its websites. */
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
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Backups')"
            :description="t('Websites are backed up with restic to S3-compatible storage: the database, the .env file and shared storage, encrypted before they leave the server.')"
        >
            <template v-if="data.canManage" #actions>
                <UiButton :variant="data.destinations.length === 0 ? 'primary' : 'secondary'" :to="{ query: { dialog: 'add-backup-destination' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a destination') }}</UiButton>
            </template>
        </ProjectHeader>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard :label="t('Last backup')" :value="data.summary.backup ? dateTime(data.summary.backup) : t('None yet')" />
            <StatCard :label="t('Last restore')" :value="data.summary.restore ? dateTime(data.summary.restore) : t('None yet')" :description="data.summary.restoreSeconds !== null ? t('Took :duration', { duration: labels.duration(data.summary.restoreSeconds) }) : null" />
            <StatCard :label="t('Last verified restore')" :value="data.summary.verification ? dateTime(data.summary.verification) : t('None yet')" />
        </div>

        <SettingsSection :title="t('Destinations')" :description="t('Buckets belong to :account; each website gets its own restic repository under the prefix.', { account: data.accountName })">
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="destination in data.destinations" :key="destination.id" class="grid gap-3 rounded-panel border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold text-ink">{{ destination.name }}</p>
                            <p class="text-xs text-muted">
                                {{ data.presets[destination.storageProvider]?.name ?? destination.storageProvider }} · <span class="font-mono">{{ destination.bucket }}/{{ destination.pathPrefix }}</span>
                                · {{ tc(':count schedule|:count schedules', destination.schedules, { count: destination.schedules }) }} · {{ tc(':count backup|:count backups', destination.backups, { count: destination.backups }) }}
                            </p>
                        </div>
                        <Badge v-if="destination.lastError" tone="danger">{{ t('Check failed') }}</Badge>
                        <Badge v-else-if="destination.lastVerifiedAt" tone="success"><Rich :text="t('Works · :when')"><template #when><RelativeTime :at="destination.lastVerifiedAt" /></template></Rich></Badge>
                        <Badge v-else>{{ t('Not checked') }}</Badge>
                    </div>
                    <p v-if="destination.lastError" class="text-sm text-danger">{{ destination.lastError }}</p>
                    <div v-if="data.canManage" class="flex flex-wrap gap-2">
                        <ApiForm :action="`${base}/${destination.id}/check`"><SubmitButton variant="secondary" size="sm">{{ t('Check connection') }}</SubmitButton></ApiForm>
                        <FormDialog :id="`edit-destination-${destination.id}`" :title="t('Edit')" :action="`${base}/${destination.id}`" method="PUT" :submit="t('Save destination')" size="wide">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                            <BackupDestinationFields :destination="destination" :presets="data.presets" />
                        </FormDialog>
                        <DeleteDialog
                            :id="`delete-destination-${destination.id}`"
                            :title="t('Delete :destination?', { destination: destination.name })"
                            :description="t('The bucket and anything in it stay as they are.')"
                            :action="`${base}/${destination.id}`"
                            :submit-label="t('Delete destination')"
                        >
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Delete') }}</UiButton></template>
                        </DeleteDialog>
                    </div>
                </div>
                <p v-if="data.destinations.length === 0" class="text-sm text-muted">{{ t('No destinations yet.') }}</p>
            </div>
        </SettingsSection>

        <SettingsSection :title="t('Recent backups')" :description="t('Run, schedule, restore and verify backups on each website’s page.')">
            <p v-if="data.backups.length === 0" class="p-4 text-sm text-muted sm:p-6">{{ t('No backups yet.') }}</p>
            <DataTable v-else :caption="t('Recent backups')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Website') }}</th><th scope="col">{{ t('Started') }}</th><th scope="col">{{ t('Destination') }}</th><th scope="col">{{ t('Size') }}</th><th scope="col">{{ t('Status') }}</th></tr>
                </template>
                <tr v-for="backup in data.backups" :key="backup.id">
                    <td>
                        <span v-if="backup.websiteDeleted">{{ backup.website }}</span>
                        <NuxtLink v-else :to="`/projects/${project.id}/infrastructure/websites/${backup.websiteId}?tab=backups`" class="font-bold text-primary hover:underline">{{ backup.website }}</NuxtLink>
                    </td>
                    <td class="text-muted">{{ backup.createdAt ? dateTime(backup.createdAt) : '—' }}</td>
                    <td>{{ backup.destination }}</td>
                    <td class="text-muted">{{ bytes(backup.sizeBytes) }}</td>
                    <td><BackupStatusBadge :status="backup.status" /></td>
                </tr>
            </DataTable>
        </SettingsSection>

        <UiDialog v-if="data.canManage" id="add-backup-destination" :title="t('Add a backup destination')" :description="t('S3-compatible storage you own. We check we can write to it before saving.')" size="wide">
            <ApiForm :action="base" class="grid gap-4">
                <BackupDestinationFields :destination="null" :presets="data.presets" />
                <ul class="grid gap-1 text-xs text-muted">
                    <li v-for="(preset, key) in data.presets" :key="key"><span class="font-bold">{{ preset.name }}:</span> {{ preset.description }} <span class="font-mono">{{ preset.endpoint }}</span></li>
                </ul>
                <div class="flex justify-end"><SubmitButton>{{ t('Add destination') }}</SubmitButton></div>
            </ApiForm>
        </UiDialog>
    </div>
</template>
