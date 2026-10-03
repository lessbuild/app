<script setup lang="ts">
/**
 * Webhooks: the account's events sent as signed JSON to its own endpoints, each with its recent deliveries, a test
 * event, sending a delivery again, and how to check signatures.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t, dateTime } = useT();
type Delivery = { id: string; event: string; status: string; responseStatus: number | null; attempts: number; error: string | null; at: string | null };
type Endpoint = { id: number; url: string; description: string | null; events: string[]; enabled: boolean; lastDeliveredAt: string | null; lastError: string | null; deliveries: Delivery[] };
type Groups = Array<{ group: string; events: Array<{ event: string; meaning: string }> }>;
const { data } = await useApi<{ account: { id: string; name: string }; endpoints: Endpoint[]; events: Groups; maxFailures: number; signatureExample: string }>('/account/webhooks');
const secret = ref<string | null>(null);
const sending = ref<string | null>(null);
const statuses = computed<Record<string, { label: string; tone: 'success' | 'danger' | 'neutral' }>>(() => ({
    delivered: { label: t('Delivered'), tone: 'success' },
    failed: { label: t('Failed'), tone: 'danger' },
    pending: { label: t('Pending'), tone: 'neutral' },
}));

/** Keep a new endpoint's signing secret to show once. */
function added(result: Record<string, unknown>): null {
    secret.value = typeof result.secret === 'string' ? result.secret : null;
    navigateTo({ query: secret.value ? { dialog: 'webhook-secret' } : {} });
    refreshPage();
    return null;
}

/** Send a test event, or one delivery again. */
async function resend(endpoint: Endpoint, delivery?: Delivery) {
    sending.value = delivery?.id ?? `endpoint-${endpoint.id}`;
    const result = await send<{ message: string }>('POST', `/account/webhooks/${endpoint.id}/send`, delivery ? { delivery: delivery.id } : {}).catch(() => null);
    if (result) {
        flash(result.message, 'info');
        await refreshPage();
    }
    sending.value = null;
}
</script>

<template>
    <div class="space-y-10">
        <PageHeader :eyebrow="data.account.name" :title="t('Webhooks')" :description="t('Send :account’s events (deploys, incidents, servers, backups, security findings, billing and more) to your own automation as signed JSON.', { account: data.account.name })">
            <template v-if="data.endpoints.length > 0" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-endpoint' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add an endpoint') }}</UiButton>
            </template>
        </PageHeader>

        <EmptyState v-if="data.endpoints.length === 0" icon="link" :title="t('No endpoints yet')" :description="t('Add an endpoint to receive events as they happen, for chat bots, dashboards, ticketing or anything else you run.')">
            <template #action><UiButton variant="primary" :to="{ query: { dialog: 'add-endpoint' } }">{{ t('Add an endpoint') }}</UiButton></template>
        </EmptyState>

        <ul v-else class="grid gap-4">
            <li v-for="endpoint in data.endpoints" :key="endpoint.id" class="ui-card grid gap-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-bold text-ink">
                            <span class="break-all">{{ endpoint.description || endpoint.url }}</span>
                            <Badge :tone="endpoint.enabled ? 'success' : 'danger'">{{ endpoint.enabled ? t('On') : t('Paused') }}</Badge>
                        </p>
                        <p v-if="endpoint.description" class="mt-1 break-all text-sm text-muted">{{ endpoint.url }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ endpoint.events.includes('*') ? t('All events') : endpoint.events.join(', ') }}
                            <template v-if="endpoint.lastDeliveredAt"> · {{ t('last delivered :time', { time: dateTime(endpoint.lastDeliveredAt) }) }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <UiButton size="sm" :disabled="sending !== null" @click="resend(endpoint)">{{ sending === `endpoint-${endpoint.id}` ? t('Working…') : t('Send a test') }}</UiButton>
                        <FormDialog :id="`edit-endpoint-${endpoint.id}`" :title="t('Edit')" :action="`/api/app/account/webhooks/${endpoint.id}`" method="PUT" :submit="t('Save')" size="large">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                            <InputField :id="`url-${endpoint.id}`" name="url" type="url" :label="t('Address')" :model-value="endpoint.url" maxlength="2048" required />
                            <InputField :id="`description-${endpoint.id}`" name="description" :label="t('Description')" :model-value="endpoint.description ?? ''" maxlength="200" />
                            <WebhookEventsField :prefix="`edit-${endpoint.id}`" :groups="data.events" :selected="endpoint.events" />
                            <CheckboxField :id="`enabled-${endpoint.id}`" name="enabled" unchecked-value="0" :label="t('On')" :checked="endpoint.enabled" />
                        </FormDialog>
                        <DeleteDialog :id="`remove-endpoint-${endpoint.id}`" :title="t('Remove endpoint')" :description="endpoint.url" :action="`/api/app/account/webhooks/${endpoint.id}`" :submit-label="t('Remove endpoint')">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove endpoint') }}</UiButton></template>
                        </DeleteDialog>
                    </div>
                </div>
                <Alert v-if="endpoint.lastError" :tone="endpoint.enabled ? 'warning' : 'danger'">
                    {{ endpoint.enabled ? t('Last failure: :error', { error: endpoint.lastError }) : t('Paused after :count failed deliveries in a row (last: :error). Fix the address, then turn it back on.', { count: data.maxFailures, error: endpoint.lastError }) }}
                </Alert>
                <Disclosure :title="t('Recent deliveries')">
                    <p v-if="endpoint.deliveries.length === 0" class="text-sm text-muted">{{ t('Nothing sent yet.') }}</p>
                    <DataTable v-else :caption="t('Recent deliveries')" :framed="false">
                        <template #head>
                            <tr>
                                <th scope="col">{{ t('Event') }}</th>
                                <th scope="col">{{ t('Result') }}</th>
                                <th scope="col">{{ t('Tries') }}</th>
                                <th scope="col">{{ t('When') }}</th>
                                <th scope="col"><span class="sr-only">{{ t('Actions') }}</span></th>
                            </tr>
                        </template>
                        <tr v-for="delivery in endpoint.deliveries" :key="delivery.id">
                            <td class="font-mono text-xs">{{ delivery.event }}</td>
                            <td>
                                <Badge :tone="statuses[delivery.status]?.tone ?? 'neutral'">{{ statuses[delivery.status]?.label ?? delivery.status }}</Badge>
                                <span v-if="delivery.responseStatus" class="ml-1 text-xs text-muted">HTTP {{ delivery.responseStatus }}</span>
                                <span v-if="delivery.error" class="block text-xs text-danger">{{ delivery.error }}</span>
                            </td>
                            <td>{{ delivery.attempts }}</td>
                            <td class="whitespace-nowrap"><RelativeTime v-if="delivery.at" :at="delivery.at" /></td>
                            <td class="text-right">
                                <UiButton variant="quiet" size="sm" :disabled="sending !== null" @click="resend(endpoint, delivery)">{{ sending === delivery.id ? t('Working…') : t('Send again') }}</UiButton>
                            </td>
                        </tr>
                    </DataTable>
                </Disclosure>
            </li>
        </ul>

        <SettingsSection id="verifying" :title="t('Checking the signature')" :description="t('Each request has X-BuildPusher-Event, X-BuildPusher-Delivery (the same ID if it’s sent again), X-BuildPusher-Timestamp and X-BuildPusher-Signature. Recompute the signature from the raw body and reject requests older than five minutes. Answer with any 2xx within 10 seconds; anything else is tried again up to six times over about three hours.')">
            <div class="p-4 sm:p-6"><CodeBlock :code="data.signatureExample" /></div>
        </SettingsSection>

        <UiDialog id="add-endpoint" :title="t('Add an endpoint')" size="large">
            <ApiForm action="/api/app/account/webhooks" :after="added">
                <InputField name="url" type="url" :label="t('Address')" :description="t('A public HTTPS address. It gets a POST for each event.')" placeholder="https://" maxlength="2048" required autofocus />
                <InputField name="description" :label="t('Description')" :placeholder="t('Deploy announcements')" maxlength="200" />
                <WebhookEventsField prefix="add" :groups="data.events" :selected="['*']" />
                <div class="flex justify-end"><SubmitButton>{{ t('Add endpoint') }}</SubmitButton></div>
            </ApiForm>
        </UiDialog>

        <UiDialog v-if="secret" id="webhook-secret" :title="t('Signing secret')" :description="t('This is the only time it is shown. Use it to check the X-BuildPusher-Signature header.')">
            <div class="grid gap-3">
                <Alert tone="warning">{{ t('Copy it now') }}</Alert>
                <CodeBlock :code="secret" />
            </div>
        </UiDialog>
    </div>
</template>
