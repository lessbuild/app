<script setup lang="ts">
/**
 * Tools for agencies: white-label branding on what clients see, a monthly report for each client, and costs by
 * client with a markup.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t, tc } = useT();
type Client = { id: number; name: string; emails: string[]; projectIds: string[]; markupPercent: number; monthlyReport: boolean };
const { data } = await useApi<{
    account: { id: string; name: string };
    clients: Client[];
    projects: Array<{ id: string; name: string }>;
    branding: { name: string | null; color: string | null; logoUrl: string | null };
    whiteLabel: boolean;
}>('/account/clients');

/** What a client gets, in one line. */
function summary(client: Client): string {
    return [
        tc(':count project|:count projects', client.projectIds.length),
        client.markupPercent > 0 ? t(':percent% markup', { percent: client.markupPercent }) : null,
        client.monthlyReport && client.emails.length > 0 ? t('monthly report to :emails', { emails: client.emails.join(', ') }) : t('no monthly report'),
    ].filter(Boolean).join(' · ');
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :eyebrow="data.account.name" :title="t('Clients')" :description="t('Tools for agencies: your own branding on what clients see, a monthly report for each client, and costs by client with your markup.')">
            <template #actions>
                <a href="/api/app/account/clients/costs.csv" class="ui-btn ui-btn-secondary" download>{{ t('Download costs by client') }}</a>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'add-client' } }" icon="plus">{{ t('Add a client') }}</AcmeBtn>
            </template>
        </PageHeader>

        <AcmeCard id="branding" :padded="false" :title="t('White label')" :description="t('Show your name, logo and colour instead of ours on status pages, shared Analytics reports and client reports, without “Powered by”. Leave the name empty to turn it off.')">
            <ApiForm action="/api/app/account/clients/branding" method="PUT" class="p-4 sm:p-6">
                <AcmeAlert v-if="!data.whiteLabel" tone="info">
                    {{ t('White labelling comes with the Deploy Team plan and above. You can set it up now; it shows once your plan includes it.') }}
                    <NuxtLink to="/account/billing?tab=deploy" class="font-bold underline">{{ t('See plans') }}</NuxtLink>
                </AcmeAlert>
                <div class="grid items-end gap-4 sm:grid-cols-2">
                    <InputField name="brand_name" :label="t('Name')" :model-value="data.branding.name ?? ''" maxlength="100" placeholder="Acme Studio" />
                    <InputField name="brand_color" :label="t('Colour')" :model-value="data.branding.color ?? ''" maxlength="7" placeholder="#1f6feb" />
                </div>
                <InputField name="brand_logo_url" type="url" :label="t('Logo address')" :model-value="data.branding.logoUrl ?? ''" maxlength="500" placeholder="https://acme.example/logo.svg" :description="t('An HTTPS image, about 40 pixels tall.')" />
                <div class="flex justify-end"><SubmitButton variant="secondary">{{ t('Save branding') }}</SubmitButton></div>
            </ApiForm>
        </AcmeCard>

        <EmptyState v-if="data.clients.length === 0" icon="users" :title="t('No clients yet')" :description="t('Add a client to group the projects you run for them, send them a monthly report and pass costs on with your markup. To let a client see their projects in the app, invite them as a viewer limited to those projects on the Members page.')" />
        <ul v-else class="grid gap-4">
            <li v-for="client in data.clients" :key="client.id" class="ui-card flex flex-wrap items-center justify-between gap-3 p-5">
                <div class="min-w-0">
                    <p class="font-semibold text-ink">{{ client.name }}</p>
                    <p class="mt-1 text-sm text-muted">{{ summary(client) }}</p>
                </div>
                <div class="flex flex-wrap gap-1">
                    <AcmeBtn :to="`/account/clients/${client.id}/report`" size="sm">{{ t('Preview last month’s report') }}</AcmeBtn>
                    <FormDialog :id="`edit-client-${client.id}`" :title="client.name" :action="`/api/app/account/clients/${client.id}`" method="PUT" :submit="t('Save')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Edit') }}</AcmeBtn></template>
                        <ClientFields :prefix="`client-${client.id}`" :projects="data.projects" :client="client" />
                    </FormDialog>
                    <DeleteDialog :id="`remove-client-${client.id}`" :title="t('Remove client')" :description="client.name" :action="`/api/app/account/clients/${client.id}`" :submit-label="t('Remove client')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove client') }}</AcmeBtn></template>
                    </DeleteDialog>
                </div>
            </li>
        </ul>

        <FormDialog id="add-client" :title="t('Add a client')" action="/api/app/account/clients" :submit="t('Add client')">
            <ClientFields prefix="new-client" :projects="data.projects" />
        </FormDialog>
    </div>
</template>
