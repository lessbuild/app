<script setup lang="ts">
import type { ServerCreateForm } from '~/types/infrastructure';

/**
 * The new server form (the Acme theme's create server page): a cloud provider and the server type as cards, then a
 * region and a size (with its monthly price), the name, Ubuntu image and recipes, with a summary and the running
 * price beside them. Regions, sizes and images are read from the provider when it's chosen. Beside the form on a wide
 * page, below it in a dialog.
 */
const props = defineProps<{ projectId: string }>();
const { t } = useT();
const money = useMoney();
const dialogLink = useDialogLink();
const form = ref<ServerCreateForm | null>(null);
const failed = ref(false);
const provider = ref<string | null>(null);
const loading = ref(false);
const chosen = reactive({ type: 'app', engine: 'mysql', region: '', size: '', image: '', name: '' });
const typeIcon: Record<string, string> = { app: 'globe', web: 'globe', database: 'sheet', 'load-balancer': 'layers', worker: 'zap', cache: 'cube' };
const databaseEngines = [{ value: 'mysql', label: 'MySQL' }, { value: 'postgres', label: 'PostgreSQL' }];

/** Read the form's choices, for a provider when one is chosen, and pick the first region, size and image. */
async function load(providerId: string | null) {
    loading.value = true;
    failed.value = false;
    try {
        form.value = await send<ServerCreateForm>('GET', `/projects/${props.projectId}/infrastructure/servers/create${providerId ? `?provider=${providerId}` : ''}`);
        provider.value = form.value.providerId;
        chosen.region = form.value.catalog?.regions[0]?.id ?? '';
        chosen.size = form.value.catalog?.sizes[0]?.id ?? '';
        chosen.image = form.value.catalog?.images.find((image) => image.label.includes('24.04'))?.id ?? form.value.catalog?.images[0]?.id ?? '';
    } catch {
        failed.value = true;
    }
    loading.value = false;
}

onMounted(() => load(null));
// A provider connected in the dialog over this form shows up in it straight away.
onDialogClosed('add-provider', () => load(provider.value));
watch(provider, (value, previous) => {
    if (previous !== null && value !== null && value !== form.value?.providerId) {
        load(value);
    }
});
const providerChoice = computed({ get: () => provider.value ?? '', set: (value: string | number) => (provider.value = String(value)) });
const choice = (items: Array<{ id: string; label: string }>) => items.map((item) => ({ value: item.id, label: item.label }));
const label = (items: Array<{ id: string; label: string }> | undefined, id: string) => items?.find((item) => item.id === id)?.label ?? '—';
const size = computed(() => form.value?.catalog?.sizes.find((item) => item.id === chosen.size) ?? null);
const type = computed(() => form.value?.types.find((item) => item.value === chosen.type) ?? null);
const providerName = computed(() => form.value?.providers.find((item) => item.value === provider.value)?.label ?? '—');
const nameOk = computed(() => chosen.name === '' || /^[a-zA-Z0-9-]{1,31}$/.test(chosen.name));

/**
 * Split a size's label into its name and its specification, leaving out the price shown beside it.
 *
 * @param text The size's label, such as "CX22 · 4 GB RAM · 2 vCPU · 40 GB disk".
 */
function sizeParts(text: string): { name: string; spec: string } {
    const [name = text, ...rest] = text.split(' · ');
    return { name, spec: rest.filter((part) => !part.includes('/month')).join(' · ') };
}
</script>

<template>
    <div class="@container">
        <p v-if="form === null && !failed" class="text-sm text-muted" role="status">{{ t('Loading regions and sizes…') }}</p>
        <AcmeAlert v-else-if="failed" tone="danger" role="alert">{{ t('Couldn’t load the form here. Open it on its own page.') }}</AcmeAlert>
        <AcmeEmptyState
            v-else-if="form && form.providers.length === 0"
            icon="cpu"
            :title="t('Connect a cloud provider first')"
            :description="t('Add a DigitalOcean, Hetzner Cloud, Vultr, Linode, AWS, Google Cloud, Azure, OVHcloud, Scaleway or UpCloud API token. It opens here, and the form picks it up straight away.')"
        >
            <AcmeBtn :to="dialogLink('add-provider')" variant="primary" icon="plus">{{ t('Connect a provider') }}</AcmeBtn>
        </AcmeEmptyState>
        <ApiForm v-else-if="form" :action="`/api/app/projects/${projectId}/infrastructure/servers`" class="!grid gap-6 @3xl:grid-cols-[1fr_20rem]">
            <input type="hidden" name="provider_id" :value="form.providerId ?? ''">
            <div class="min-w-0 space-y-6">
                <AcmeCard :title="t('Provider and type')">
                    <AcmeSegmented v-if="form.providers.length <= 3" v-model="providerChoice" :label="t('Provider')" :options="form.providers" />
                    <SelectField v-else id="server-provider" v-model="provider" name="provider" :label="t('Provider')" :options="form.providers" :disabled="loading" />
                    <div class="mt-5 grid gap-3 @lg:grid-cols-2" role="radiogroup" :aria-label="t('Server type')">
                        <label v-for="option in form.types" :key="option.value" :class="['flex cursor-pointer gap-3 rounded-xl border p-4 transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent', chosen.type === option.value ? 'border-accent bg-accent/[.04] ring-1 ring-accent' : 'border-line hover:bg-black/[.02] dark:hover:bg-white/[.03]']">
                            <input v-model="chosen.type" type="radio" name="type" :value="option.value" class="sr-only">
                            <AcmeIconBubble :icon="typeIcon[option.value] ?? 'cpu'" />
                            <span><b class="block text-sm font-medium text-ink">{{ option.name }}</b><span class="text-xs text-muted">{{ option.installs }}</span></span>
                        </label>
                    </div>
                    <SelectField v-if="chosen.type === 'database'" id="server-engine" v-model="chosen.engine" name="database_engine" :label="t('Database engine')" :options="databaseEngines" class="mt-4 max-w-xs" />
                </AcmeCard>

                <AcmeAlert v-if="form.catalogError" tone="danger" role="alert">{{ form.catalogError }}</AcmeAlert>
                <p v-else-if="loading" class="text-sm text-muted" role="status">{{ t('Loading regions and sizes…') }}</p>
                <template v-else-if="form.catalog">
                    <AcmeCard :title="t('Region and size')">
                        <SelectField id="server-region" v-model="chosen.region" name="region" :label="t('Region')" :options="choice(form.catalog.regions)" class="max-w-sm" required />
                        <div class="mt-5 grid max-h-[26rem] gap-3 overflow-y-auto p-0.5 @lg:grid-cols-2" role="radiogroup" :aria-label="t('Size')">
                            <label v-for="option in form.catalog.sizes" :key="option.id" :class="['flex cursor-pointer items-center justify-between gap-3 rounded-xl border p-4 transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent', chosen.size === option.id ? 'border-accent bg-accent/[.04] ring-1 ring-accent' : 'border-line hover:bg-black/[.02] dark:hover:bg-white/[.03]']">
                                <input v-model="chosen.size" type="radio" name="size" :value="option.id" class="sr-only">
                                <span class="min-w-0"><b class="block truncate text-sm font-medium text-ink">{{ sizeParts(option.label).name }}</b><span class="text-xs text-muted">{{ sizeParts(option.label).spec }}</span></span>
                                <span v-if="option.price !== null" class="shrink-0 text-sm font-semibold tabular-nums text-ink">{{ money.amount(option.price, option.currency) }}<span class="text-xs font-normal text-muted">{{ t('/mo') }}</span></span>
                            </label>
                        </div>
                    </AcmeCard>

                    <AcmeCard :title="t('Name and image')">
                        <div class="grid gap-4 @lg:grid-cols-2">
                            <InputField id="server-name" v-model="chosen.name" name="name" :label="t('Name')" maxlength="31" placeholder="web-1" :description="nameOk ? t('Letters, numbers and dashes. Used as the hostname.') : t('Use letters, numbers and dashes only.')" required />
                            <SelectField id="server-image" v-model="chosen.image" name="image" :label="t('Ubuntu image')" :options="choice(form.catalog.images)" required />
                        </div>
                        <fieldset v-if="form.recipes.length > 0" class="mt-5 grid gap-1">
                            <legend class="mb-1 text-sm font-medium text-ink">{{ t('Recipes') }}</legend>
                            <p class="text-xs text-muted">{{ t('Scripts that run as root at the end of provisioning, in this order. The server keeps the version it ran.') }}</p>
                            <div class="mt-2 grid gap-2">
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
                            </div>
                        </fieldset>
                    </AcmeCard>
                </template>
            </div>
            <aside class="@3xl:sticky @3xl:top-4 @3xl:self-start">
                <AcmeCard :title="t('Summary')">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Provider') }}</dt><dd class="truncate text-right text-ink">{{ providerName }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Type') }}</dt><dd class="text-right text-ink">{{ type?.name ?? '—' }}<template v-if="chosen.type === 'database'"> · {{ chosen.engine === 'postgres' ? 'PostgreSQL' : 'MySQL' }}</template></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Region') }}</dt><dd class="truncate text-right text-ink">{{ label(form.catalog?.regions, chosen.region) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Size') }}</dt><dd class="truncate text-right text-ink">{{ size ? sizeParts(size.label).name : '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Image') }}</dt><dd class="truncate text-right text-ink">{{ label(form.catalog?.images, chosen.image) }}</dd></div>
                    </dl>
                    <p v-if="size && size.price !== null" class="mt-4 flex items-baseline justify-between gap-3 border-t border-line pt-4">
                        <span class="text-sm text-muted">{{ t('Billed by your provider') }}</span>
                        <b class="text-2xl font-semibold tabular-nums text-ink">{{ money.amount(size.price, size.currency) }}<span class="text-sm font-normal text-muted">{{ t('/mo') }}</span></b>
                    </p>
                    <p class="mt-3 text-xs text-muted">{{ t('A new SSH key is made for it. Root and database passwords are shown once, after creation.') }}</p>
                    <SubmitButton class="mt-5 w-full justify-center" :disabled="!form.catalog || loading">{{ t('Create server') }}</SubmitButton>
                </AcmeCard>
            </aside>
        </ApiForm>
    </div>
</template>
