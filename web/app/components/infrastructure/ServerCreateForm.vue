<script setup lang="ts">
import type { ServerCreateForm } from '~/types/infrastructure';

/**
 * The new server form: a cloud provider, then its regions, sizes and Ubuntu images (read from the provider when it's
 * chosen), the server type, and recipes to run at the end of provisioning.
 */
const props = defineProps<{ projectId: string }>();
const { t } = useT();
const form = ref<ServerCreateForm | null>(null);
const failed = ref(false);
const provider = ref<string | null>(null);
const loading = ref(false);

/** Read the form's choices, for a provider when one is chosen. */
async function load(providerId: string | null) {
    loading.value = true;
    failed.value = false;
    try {
        form.value = await send<ServerCreateForm>('GET', `/projects/${props.projectId}/infrastructure/servers/create${providerId ? `?provider=${providerId}` : ''}`);
        provider.value = form.value.providerId;
    } catch {
        failed.value = true;
    }
    loading.value = false;
}

onMounted(() => load(null));
watch(provider, (value, previous) => {
    if (previous !== null && value !== null && value !== form.value?.providerId) {
        load(value);
    }
});
const choice = (items: Array<{ id: string; label: string }>) => items.map((item) => ({ value: item.id, label: item.label }));
const databaseEngines = [{ value: 'mysql', label: 'MySQL' }, { value: 'postgres', label: 'PostgreSQL' }];
</script>

<template>
    <div class="grid gap-5">
        <p v-if="form === null && !failed" class="text-sm text-muted" role="status">{{ t('Loading regions and sizes…') }}</p>
        <Alert v-else-if="failed" tone="danger" role="alert">{{ t('Couldn’t load the form here. Open it on its own page.') }}</Alert>
        <EmptyState
            v-else-if="form && form.providers.length === 0"
            icon="server"
            :title="t('Connect a cloud provider first')"
            :description="t('Add a DigitalOcean, Hetzner Cloud, Vultr, Linode, AWS, Google Cloud, Azure, OVHcloud, Scaleway or UpCloud credential on the account’s Providers page.')"
        >
            <template #action><UiButton to="/account/providers">{{ t('Providers') }}</UiButton></template>
        </EmptyState>
        <template v-else-if="form">
            <SelectField v-model="provider" name="provider" :label="t('Provider')" :options="form.providers" :disabled="loading" />
            <Alert v-if="form.catalogError" tone="danger" role="alert">{{ form.catalogError }}</Alert>
            <p v-else-if="loading" class="text-sm text-muted" role="status">{{ t('Loading regions and sizes…') }}</p>
            <ApiForm v-else-if="form.catalog" :action="`/api/app/projects/${projectId}/infrastructure/servers`" class="grid items-start gap-5 sm:grid-cols-2">
                <input type="hidden" name="provider_id" :value="form.providerId ?? ''">
                <InputField name="name" :label="t('Name')" maxlength="31" placeholder="web-1" :description="t('Letters, numbers and dashes. Used as the hostname.')" required autofocus />
                <SelectField name="type" :label="t('Type')" :options="form.types" model-value="app" required />
                <SelectField name="database_engine" :label="t('Database engine')" :options="databaseEngines" :description="t('For database servers.')" />
                <SelectField name="region" :label="t('Region')" :options="choice(form.catalog.regions)" required />
                <SelectField name="size" :label="t('Size')" :options="choice(form.catalog.sizes)" required />
                <SelectField name="image" :label="t('Ubuntu image')" :options="choice(form.catalog.images)" required />
                <fieldset v-if="form.recipes.length > 0" class="grid gap-1 sm:col-span-2">
                    <legend class="mb-1 text-sm font-bold text-ink">{{ t('Recipes') }}</legend>
                    <p class="ui-help">{{ t('Scripts that run as root at the end of provisioning, in this order. The server keeps the version it ran.') }}</p>
                    <CheckboxField
                        v-for="recipe in form.recipes"
                        :id="`recipe-${recipe.id}`"
                        :key="recipe.id"
                        name="recipe_ids[]"
                        error-key="recipe_ids"
                        :value="String(recipe.id)"
                        :label="recipe.name"
                        :description="recipe.description ?? undefined"
                    />
                </fieldset>
                <p class="text-sm text-muted sm:col-span-2">{{ t('The provider bills you for the server. A new SSH key is made for it; the root and MySQL passwords are shown once, after creation.') }}</p>
                <div class="flex justify-end sm:col-span-2"><SubmitButton>{{ t('Create server') }}</SubmitButton></div>
            </ApiForm>
        </template>
    </div>
</template>
