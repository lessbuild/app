<script setup lang="ts">
import type { ProviderType } from '~/types/providers';

/** A provider's fields: its name and type, a self-hosted GitLab's address, the API token and a description. */
const props = defineProps<{ types: ProviderType[]; provider?: { name: string; type: string; baseUrl: string | null; description: string | null } }>();
const { t } = useT();
const type = ref(props.provider?.type ?? props.types[0]?.value ?? '');
const options = computed(() => props.types.map((item) => ({ value: item.value, label: `${item.label} · ${item.purpose}` })));
</script>

<template>
    <InputField name="name" :label="t('Name')" :model-value="provider?.name ?? ''" maxlength="120" :placeholder="t('Production cloud')" required />
    <SelectField v-model="type" name="type" :label="t('Type')" :options="options" required />
    <InputField v-if="type === 'gitlab'" name="base_url" type="url" :label="t('GitLab address (self-hosted only)')" :model-value="provider?.baseUrl ?? ''" placeholder="https://gitlab.example.com" :description="t('For GitLab providers on your own server. Leave empty for gitlab.com and for other types.')" autocomplete="off" class="sm:col-span-2" />
    <PasswordField
        name="token"
        :label="provider ? t('New API token') : t('API token')"
        :description="provider ? t('Leave empty to keep the current token. It’s stored encrypted and never shown again.') : t('Stored encrypted and never shown again. Give it only the access servers or DNS need. For AWS Lightsail and EC2, enter ACCESS_KEY_ID:SECRET_ACCESS_KEY; for Google Compute Engine, paste a service account’s JSON key; for Azure, TENANT_ID:CLIENT_ID:SUBSCRIPTION_ID:CLIENT_SECRET; for OVHcloud, ENDPOINT:APPLICATION_KEY:APPLICATION_SECRET:CONSUMER_KEY:PROJECT_ID; for Scaleway, PROJECT_ID:SECRET_KEY; for UpCloud, an API user’s USERNAME:PASSWORD.')"
        autocomplete="off"
        :required="!provider"
    />
    <TextareaField name="description" :label="t('Description')" :model-value="provider?.description ?? ''" maxlength="1000" rows="2" />
</template>
