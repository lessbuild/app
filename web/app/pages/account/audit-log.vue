<script setup lang="ts">
import type { AuditEntryView } from '~/types/projects';

/**
 * Who changed what in the account: filtered by project, person, kind of change and dates (kept in the address, so a
 * view can be shared), exported as CSV, and streamed elsewhere as it happens.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t, dateTime } = useT();
const route = useRoute();
type Stream = { id: number; name: string; type: string; destination: string | null; enabled: boolean; lastError: string | null; lastDeliveredAt: string | null };
type AuditLog = {
    account: { id: string; name: string };
    entries: AuditEntryView[];
    nextCursor: string | null;
    previousCursor: string | null;
    filters: { project: string | null; person: string | null; category: string | null; from: string | null; to: string | null };
    projects: Array<{ id: string; name: string }>;
    members: Array<{ id: string; name: string }>;
    categories: Record<string, string>;
    retentionDays: number;
    streams: Stream[];
    streamTypes: Record<string, string>;
    backupDestinations: Array<{ id: number; name: string }>;
    canManageStreams: boolean;
};
const keys = ['project', 'person', 'category', 'from', 'to', 'cursor'] as const;
const query = () => Object.fromEntries(keys.map((key) => [key, typeof route.query[key] === 'string' ? route.query[key] : undefined]));
const { data } = await useApi<AuditLog>('/account/audit-log', query);
const filters = reactive({ ...data.value.filters });
watch(() => data.value.filters, (value) => Object.assign(filters, value));
const secret = ref<string | null>(null);
const streamType = ref('slack');

/** Show the log with the chosen filters, from the newest entry. */
function apply() {
    navigateTo({ query: Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) });
}

const exportUrl = computed(() => `/api/app/account/audit-log/export?${new URLSearchParams(Object.entries(data.value.filters).filter((entry): entry is [string, string] => !!entry[1])).toString()}`);
const page = (cursor: string | null) => ({ query: { ...route.query, cursor: cursor ?? undefined } });
const projectOptions = computed(() => data.value.projects.map((project) => ({ value: project.id, label: project.name })));
const personOptions = computed(() => data.value.members.map((member) => ({ value: member.id, label: member.name })));
const categoryOptions = computed(() => Object.entries(data.value.categories).map(([value, label]) => ({ value, label })));

/** Keep a new webhook stream's signing secret to show once. */
function added(result: Record<string, unknown>): null {
    secret.value = typeof result.secret === 'string' ? result.secret : null;
    navigateTo({ query: { ...route.query, dialog: undefined } });
    refreshPage();
    return null;
}
</script>

<template>
    <SettingsFrame :title="t('Audit log')" :description="t('Who changed what in this account over the last :days days.', { days: data.retentionDays })">
            <template #actions>
                <a :href="exportUrl" class="ui-btn ui-btn-secondary" download><Icon name="arrow-down" class="h-4 w-4" />{{ t('Export CSV') }}</a>
            </template>

        <form class="ui-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto] lg:items-end" @submit.prevent="apply">
            <SelectField v-if="data.projects.length > 0" v-model="filters.project" name="project" :label="t('Project')" :placeholder="t('All of :account', { account: data.account.name })" :options="projectOptions" />
            <SelectField v-model="filters.person" name="person" :label="t('Person')" :placeholder="t('Anyone')" :options="personOptions" />
            <SelectField v-model="filters.category" name="category" :label="t('Kind of change')" :placeholder="t('Everything')" :options="categoryOptions" />
            <InputField v-model="filters.from" name="from" type="date" :label="t('From')" />
            <InputField v-model="filters.to" name="to" type="date" :label="t('To')" />
            <UiButton type="submit">{{ t('Show') }}</UiButton>
        </form>

        <SavedViews page="audit-log" :keys="['project', 'person', 'category', 'from', 'to']" />

        <EmptyState v-if="data.entries.length === 0" icon="list" :title="t('Nothing recorded yet')" :description="t('Invitations, role changes and other account changes will appear here.')" />
        <template v-else>
            <div class="ui-card overflow-hidden">
                <DataTable :caption="t('Audit log')" :framed="false">
                    <template #head>
                        <tr>
                            <th scope="col">{{ t('When') }}</th>
                            <th scope="col">{{ t('Who') }}</th>
                            <th scope="col">{{ t('What') }}</th>
                            <th scope="col">{{ t('From') }}</th>
                        </tr>
                    </template>
                    <tr v-for="entry in data.entries" :key="entry.id">
                        <td class="whitespace-nowrap"><RelativeTime :at="entry.at" /></td>
                        <td>
                            <span class="block font-bold text-ink">{{ entry.actor }}</span>
                            <span v-if="entry.actorEmail" class="block text-xs text-muted">{{ entry.actorEmail }}</span>
                        </td>
                        <td>{{ entry.description }}</td>
                        <td>
                            <span class="block">{{ entry.ipAddress ?? '—' }}</span>
                            <span v-if="entry.device" class="block text-xs text-muted">{{ entry.device }}</span>
                        </td>
                    </tr>
                </DataTable>
            </div>
            <nav v-if="data.nextCursor || data.previousCursor" class="flex justify-between gap-3" :aria-label="t('Audit log pages')">
                <UiButton :to="page(data.previousCursor)" :class="!data.previousCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.previousCursor || undefined">{{ t('Newer') }}</UiButton>
                <UiButton :to="page(data.nextCursor)" :class="!data.nextCursor && 'pointer-events-none opacity-50'" :aria-disabled="!data.nextCursor || undefined">{{ t('Older') }}</UiButton>
            </nav>
        </template>

        <SettingsSection id="streams" :title="t('Streams')" :description="t('Send every new entry, as it happens, to a Slack channel, a signed webhook (for a SIEM) or S3-compatible storage for long-term keeping. A stream that fails 20 times in a row is paused.')">
            <div class="grid gap-3 p-4 sm:p-6">
                <Alert v-if="secret" tone="info">{{ t('Signing secret (shown once): :secret — requests carry X-BuildPusher-Signature: v1=HMAC-SHA256 of the timestamp, a dot and the body.', { secret }) }}</Alert>
                <p v-if="data.streams.length === 0" class="text-sm text-muted">{{ t('No streams. Entries stay here for :days days.', { days: data.retentionDays }) }}</p>
                <div v-for="stream in data.streams" :key="stream.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold">{{ stream.name }}</span>
                        <span class="text-muted"> · {{ stream.type }}<template v-if="stream.destination"> ({{ stream.destination }})</template></span>
                        <Badge v-if="!stream.enabled" tone="danger" class="ml-2">{{ t('Paused') }}</Badge>
                        <span v-if="stream.lastError" class="block text-xs text-danger">{{ stream.lastError }}</span>
                        <span v-else-if="stream.lastDeliveredAt" class="block text-xs text-muted">{{ t('Last sent :time', { time: dateTime(stream.lastDeliveredAt) }) }}</span>
                    </span>
                    <DeleteDialog v-if="data.canManageStreams" :id="`remove-stream-${stream.id}`" :title="t('Remove :name?', { name: stream.name })" :action="`/api/app/account/audit-log/streams/${stream.id}`" :submit-label="t('Remove')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                    </DeleteDialog>
                </div>
                <div v-if="data.canManageStreams"><UiButton :to="{ query: { dialog: 'add-stream' } }">{{ t('Add a stream') }}</UiButton></div>
            </div>
        </SettingsSection>

        <UiDialog v-if="data.canManageStreams" id="add-stream" :title="t('Add an audit stream')" size="large">
            <ApiForm action="/api/app/account/audit-log/streams" :after="added">
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <InputField id="stream-name" name="name" :label="t('Name')" maxlength="120" placeholder="SIEM" required />
                    <SelectField id="stream-type" v-model="streamType" name="type" :label="t('Send to')" :options="Object.entries(data.streamTypes).map(([value, label]) => ({ value, label }))" />
                </div>
                <InputField v-if="streamType !== 's3'" id="stream-url" name="endpoint_url" type="url" :label="t('Slack or webhook address')" :description="t('For Slack and webhooks.')" maxlength="2048" required />
                <SelectField v-else id="stream-destination" name="backup_destination_id" :label="t('Backup destination')" :description="t('For S3: one JSON file per entry under audit/ in the destination’s bucket.')" :placeholder="t('None')" :options="data.backupDestinations.map((destination) => ({ value: String(destination.id), label: destination.name }))" />
                <div class="flex justify-end"><SubmitButton>{{ t('Add stream') }}</SubmitButton></div>
            </ApiForm>
        </UiDialog>
    </SettingsFrame>
</template>
