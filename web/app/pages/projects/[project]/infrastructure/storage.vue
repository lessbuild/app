<script setup lang="ts">
import type { StoragePresets } from '~/types/infrastructure';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** The project's S3-compatible buckets for uploads and media, and the environment each is attached to. */
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
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Storage')"
            :description="t('S3-compatible buckets for uploads, media and exports. Attach one to an environment and Laravel’s s3 disk uses it after the next deploy.')"
        >
            <template v-if="data.canManage" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-bucket' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add a bucket') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.buckets.length === 0" icon="database" :title="t('No buckets yet')" :description="t('Keep uploads and media in object storage instead of on a server, so every replica and server sees the same files.')" />
        <section v-for="bucket in data.buckets" :key="bucket.id" class="ui-card grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center" :aria-label="bucket.name">
            <div class="min-w-0">
                <p class="font-bold text-ink">{{ bucket.name }} <span class="font-mono text-sm font-normal text-muted">{{ bucket.bucket }}</span></p>
                <p class="text-sm text-muted">{{ data.presets[bucket.storageProvider]?.name ?? bucket.storageProvider }} · {{ bucket.region }} · <span class="font-mono text-xs">{{ bucket.endpoint }}</span></p>
                <p class="text-xs text-muted">{{ bucket.environment ? t('Attached to :environment', { environment: bucket.environment }) : t('Not attached to an environment yet') }}</p>
            </div>
            <div v-if="data.canManage" class="flex flex-wrap items-end gap-2">
                <ApiForm :action="`${base}/${bucket.id}/attach`" class="flex flex-wrap items-end gap-2">
                    <SelectField :id="`attach-${bucket.id}`" name="environment_id" :label="t('Environment')" :options="data.environments" :model-value="bucket.environmentId ?? data.environments[0]?.value" />
                    <SubmitButton variant="secondary" size="sm">{{ t('Attach') }}</SubmitButton>
                </ApiForm>
                <ApiForm :action="`${base}/${bucket.id}`" method="DELETE" :confirm="t('Remove :name?', { name: bucket.name })"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
            </div>
        </section>

        <FormDialog
            v-if="data.canManage"
            id="add-bucket"
            :title="t('Add a bucket')"
            :description="t('Create a new bucket with your storage keys, or add one you already have. The keys are stored encrypted.')"
            :action="base"
            :submit="t('Add bucket')"
            size="wide"
        >
            <div class="grid items-start gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><InputField name="name" :label="t('Name')" placeholder="Uploads" maxlength="60" required autofocus /></div>
                <SelectField name="storage_provider" :label="t('Storage service')" :options="services" />
                <InputField name="region" :label="t('Region')" placeholder="fra1, eu-central-1 or auto" maxlength="60" required />
                <div class="sm:col-span-2">
                    <InputField name="endpoint" type="url" :label="t('Endpoint')" placeholder="https://<account-id>.r2.cloudflarestorage.com" :description="t('Filled in for Spaces and Amazon S3 from the region; needed for R2 and others.')" maxlength="255" />
                </div>
                <InputField name="bucket" :label="t('Bucket name')" placeholder="acme-uploads" maxlength="63" required />
                <CheckboxField name="create" :label="t('Create it now')" :description="t('Leave off to add a bucket that already exists.')" />
                <InputField name="access_key" :label="t('Access key')" maxlength="255" autocomplete="off" required />
                <InputField name="secret_key" type="password" :label="t('Secret key')" maxlength="255" autocomplete="new-password" required />
            </div>
        </FormDialog>
    </div>
</template>
