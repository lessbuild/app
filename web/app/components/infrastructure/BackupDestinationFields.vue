<script setup lang="ts">
import type { StoragePresets } from '~/types/infrastructure';

/** A backup destination's fields; `destination` is null for a new one (whose keys are then required). */
const props = defineProps<{
    destination: { name: string; storageProvider: string; region: string; endpoint: string | null; bucket: string; pathPrefix: string } | null;
    presets: StoragePresets;
}>();
const { t } = useT();
const storage = ref<string | null>(props.destination?.storageProvider ?? 'digitalocean_spaces');
const storages = computed(() => Object.entries(props.presets).map(([value, preset]) => ({ value, label: preset.name })));
const keep = computed(() => (props.destination ? t('Leave blank to keep') : undefined));
</script>

<template>
    <div class="grid items-start gap-4 sm:grid-cols-2">
        <InputField name="name" :label="t('Name')" :model-value="destination?.name" maxlength="120" required />
        <SelectField v-model="storage" name="storage_provider" :label="t('Storage')" :options="storages" />
        <InputField name="region" :label="t('Region')" :model-value="destination?.region" placeholder="ams3" maxlength="64" required />
        <InputField name="endpoint" :label="t('Endpoint')" :model-value="destination?.endpoint" placeholder="https://ams3.digitaloceanspaces.com" :description="t('Filled in from the region for Spaces and Amazon S3.')" maxlength="255" />
        <InputField name="bucket" :label="t('Bucket')" :model-value="destination?.bucket" maxlength="63" required />
        <InputField name="path_prefix" :label="t('Path prefix')" :model-value="destination?.pathPrefix ?? 'buildpusher'" maxlength="120" required />
        <InputField name="access_key" :label="t('Access key')" autocomplete="off" maxlength="1000" :placeholder="keep" :required="!destination" />
        <InputField name="secret_key" type="password" :label="t('Secret key')" autocomplete="new-password" maxlength="1000" :placeholder="keep" :required="!destination" />
    </div>
</template>
