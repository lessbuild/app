<script setup lang="ts">
import type { StoragePresets } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * The project's S3-compatible buckets for uploads and media (the Acme theme's storage page): a card each with its
 * service, region and endpoint, and the environment it's attached to.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
type Bucket = { id: number; name: string; bucket: string; storageProvider: string; region: string; endpoint: string; environmentId: string | null; environment: string | null };
type StoragePage = { overview: ProjectOverview; buckets: Bucket[]; environments: Option[]; presets: StoragePresets; canManage: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<StoragePage>(() => `/projects/${route.params.project}/infrastructure/storage`);
const base = computed(() => `/api/app/projects/${data.value.overview.project.id}/infrastructure/storage`);
const services = computed(() => Object.entries(data.value.presets).map(([value, preset]) => ({ value, label: preset.name })));
</script>

<template>
    <div>
        <ProjectHeader
            :overview="data.overview"
            :title="t('Infrastructure')"
            :description="t('S3-compatible buckets for uploads, media and exports. Attach one to an environment and Laravel’s s3 disk uses it after the next deploy.')"
        >
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ query: { dialog: 'add-bucket' } }">{{ t('Add a bucket') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <AcmeEmptyState v-if="data.buckets.length === 0" icon="folder" :title="t('No buckets yet')" :description="t('Keep uploads and media in object storage instead of on a server, so every replica and server sees the same files.')" />
        <ul v-else class="grid gap-4 md:grid-cols-2">
            <li v-for="bucket in data.buckets" :key="bucket.id" class="flex flex-col rounded-2xl border border-line bg-surface p-5 shadow-card">
                <div class="flex items-start gap-3">
                    <AcmeIconBubble icon="folder" size="md" />
                    <span class="min-w-0 flex-1">
                        <b class="block truncate font-semibold text-ink">{{ bucket.name }}</b>
                        <span class="text-xs text-muted">{{ data.presets[bucket.storageProvider]?.name ?? bucket.storageProvider }} · {{ bucket.region }}</span>
                    </span>
                    <ApiForm v-if="data.canManage" :action="`${base}/${bucket.id}`" method="DELETE" :confirm="t('Remove :name?', { name: bucket.name })">
                        <AcmeBtn type="submit" variant="ghost" size="sm" icon="trash" :label="t('Remove :name', { name: bucket.name })" />
                    </ApiForm>
                </div>
                <dl class="my-4 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-muted">{{ t('Bucket') }}</dt><dd class="mt-0.5 truncate font-mono text-xs font-medium text-ink">{{ bucket.bucket }}</dd></div>
                    <div><dt class="text-xs text-muted">{{ t('Endpoint') }}</dt><dd class="mt-0.5 truncate font-mono text-xs text-ink" :title="bucket.endpoint">{{ bucket.endpoint }}</dd></div>
                </dl>
                <div class="mt-auto flex flex-wrap items-end gap-2 border-t border-line pt-4">
                    <AcmeBadge v-if="bucket.environment" tone="green" dot>{{ t('Attached to :environment', { environment: bucket.environment }) }}</AcmeBadge>
                    <span v-else class="text-xs text-muted">{{ t('Not attached to an environment yet') }}</span>
                    <ApiForm v-if="data.canManage" :action="`${base}/${bucket.id}/attach`" class="flex w-full flex-wrap items-end gap-2">
                        <SelectField :id="`attach-${bucket.id}`" name="environment_id" :label="t('Environment')" :options="data.environments" :model-value="bucket.environmentId ?? data.environments[0]?.value" class="flex-1" />
                        <SubmitButton variant="secondary" size="sm">{{ bucket.environment ? t('Move') : t('Attach') }}</SubmitButton>
                    </ApiForm>
                </div>
            </li>
        </ul>

        <FormDialog
            v-if="data.canManage"
            id="add-bucket"
            :title="t('Add a bucket')"
            :description="t('Create a new bucket with your storage keys, or add one you already have. The keys are stored encrypted.')"
            :action="base"
            :submit="t('Add bucket')"
        >
            <div class="grid items-start gap-4">
                <InputField name="name" :label="t('Name')" placeholder="Uploads" maxlength="60" required autofocus />
                <SelectField name="storage_provider" :label="t('Storage service')" :options="services" />
                <InputField name="region" :label="t('Region')" placeholder="fra1, eu-central-1 or auto" maxlength="60" required />
                <InputField name="endpoint" type="url" :label="t('Endpoint')" placeholder="https://<account-id>.r2.cloudflarestorage.com" :description="t('Filled in for Spaces and Amazon S3 from the region; needed for R2 and others.')" maxlength="255" />
                <InputField name="bucket" :label="t('Bucket name')" placeholder="acme-uploads" maxlength="63" required />
                <InputField name="access_key" :label="t('Access key')" maxlength="255" autocomplete="off" required />
                <InputField name="secret_key" type="password" :label="t('Secret key')" maxlength="255" autocomplete="new-password" required />
                <ToggleField name="create" :label="t('Create it now')" :description="t('Leave off to add a bucket that already exists.')" />
            </div>
        </FormDialog>
    </div>
</template>
