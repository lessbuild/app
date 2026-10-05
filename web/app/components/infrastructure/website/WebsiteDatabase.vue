<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's MySQL database: its size and tables, slow queries and tuning, extra users, and copying it elsewhere. */
const props = defineProps<{ page: WebsitePage; base: string }>();
const { t, tc, number, dateTime } = useT();
const bytes = useFileSize();
const inspection = computed(() => props.page.inspection);
const privileges = computed(() => Object.entries(props.page.privileges).map(([value, label]) => ({ value, label })));
const expiries = computed(() => [1, 7, 30, 90].map((days) => ({ value: String(days), label: tc('In :count day|In :count days', days, { count: days }) })));
const expiry = ref<string | null>('');
const targets = computed(() => props.page.copyTargets.map((target) => ({ value: target.value, label: target.production ? `${target.label} (${t('production')})` : target.label, disabled: target.production })));
const copyStatuses = computed<Record<string, string>>(() => ({ succeeded: t('Done'), failed: t('Failed'), running: t('Running'), queued: t('Queued') }));
const userStatuses = computed<Record<string, { tone: 'success' | 'danger' | 'neutral'; label: string }>>(() => ({
    active: { tone: 'success', label: t('Active') },
    failed: { tone: 'danger', label: t('Failed') },
    removing: { tone: 'neutral', label: t('Removing') },
    pending: { tone: 'neutral', label: t('Adding') },
}));
let timer: number | undefined;

// While an inspection runs, look again every few seconds.
watch(() => inspection.value !== null && !['ready', 'failed'].includes(inspection.value.status), (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 4000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <AcmeCard
id="database"
        :padded="false"
        :title="t('Database')"
        :description="t('The MySQL database :database on the website’s server: its size and tables, extra logins, and copying it into another website.', { database: page.website.database })"
    >
        <div class="grid gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
            <AcmeAlert v-if="page.canManage && !page.canManageDatabase" tone="info">{{ t('Database tools come with the Pro Deploy plan and above.') }}</AcmeAlert>

            <div class="grid gap-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold text-ink">{{ t('Inspection') }}</h3>
                    <ApiForm v-if="page.canManageDatabase" :action="`${base}/database/inspect`">
                        <SubmitButton variant="secondary" size="sm" :disabled="page.website.status !== 'active'">{{ t('Inspect now') }}</SubmitButton>
                    </ApiForm>
                </div>
                <p v-if="inspection === null" class="text-sm text-muted">{{ t('Not inspected yet. Live websites are inspected every day.') }}</p>
                <p v-else-if="inspection.status === 'failed'" class="text-sm text-danger">{{ inspection.error }}</p>
                <p v-else-if="inspection.status !== 'ready'" class="text-sm text-muted" role="status">{{ t('Inspecting…') }}</p>
                <template v-else>
                    <dl class="grid gap-4 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-muted">{{ t('Size') }}</dt><dd class="mt-1 font-bold">{{ bytes(inspection.sizeBytes ?? 0) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Tables') }}</dt><dd class="mt-1 font-bold">{{ number(inspection.tables.length) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Connections') }}</dt><dd class="mt-1 font-bold">{{ inspection.connections ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Checked') }}</dt><dd class="mt-1"><RelativeTime v-if="inspection.collectedAt" :at="inspection.collectedAt" /></dd></div>
                    </dl>
                    <Disclosure v-if="inspection.tables.length > 0" :title="t('Tables')">
                        <p class="break-words font-mono text-xs text-muted">{{ inspection.tables.join(', ') }}</p>
                    </Disclosure>
                    <section v-if="inspection.tuning.length > 0" class="grid gap-2" aria-labelledby="db-tuning">
                        <h3 id="db-tuning" class="text-sm font-bold text-ink">{{ t('Tuning suggestions') }}</h3>
                        <ul class="grid list-disc gap-1 pl-5 text-sm text-muted"><li v-for="(suggestion, index) in inspection.tuning" :key="index">{{ suggestion }}</li></ul>
                    </section>
                    <section class="grid gap-2" aria-labelledby="db-slow">
                        <h3 id="db-slow" class="text-sm font-bold text-ink">{{ t('Slow queries this week') }}</h3>
                        <template v-if="!inspection.slowLogEnabled">
                            <p class="text-sm text-muted">{{ t('The slow query log is off, so slow queries aren’t recorded.') }}</p>
                            <ApiForm v-if="page.canManageDatabase" :action="`${base}/database/slow-log`"><SubmitButton variant="secondary" size="sm">{{ t('Turn on the slow query log') }}</SubmitButton></ApiForm>
                        </template>
                        <p v-else-if="inspection.slowQueries.length === 0" class="text-sm text-muted">{{ t('None over a second this week.') }}</p>
                        <DataTable v-else :caption="t('Slowest queries this week')" :framed="false">
                            <template #head>
                                <tr>
                                    <th scope="col">{{ t('Query') }}</th><th scope="col" class="text-right">{{ t('Times') }}</th><th scope="col" class="text-right">{{ t('Average (s)') }}</th>
                                    <th scope="col" class="text-right">{{ t('Slowest (s)') }}</th><th scope="col" class="text-right">{{ t('Rows read') }}</th>
                                </tr>
                            </template>
                            <tr v-for="(query, index) in inspection.slowQueries" :key="index">
                                <td class="max-w-xl break-words font-mono text-xs">{{ query.query }}</td>
                                <td class="text-right tabular-nums">{{ number(query.count) }}</td>
                                <td class="text-right tabular-nums">{{ query.average }}</td>
                                <td class="text-right tabular-nums">{{ query.slowest }}</td>
                                <td class="text-right tabular-nums">{{ number(query.rows) }}</td>
                            </tr>
                        </DataTable>
                    </section>
                </template>
            </div>

            <div class="grid gap-3 border-t border-line pt-5">
                <h3 class="font-bold text-ink">{{ t('Users') }}</h3>
                <p v-if="page.databaseUsers.length === 0" class="text-sm text-muted">{{ t('Only the website’s own user, :user.', { user: page.website.database }) }}</p>
                <ul v-else class="divide-y divide-line text-sm">
                    <li v-for="user in page.databaseUsers" :key="user.id" class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p>
                                <span class="font-mono font-bold">{{ user.username }}</span>
                                <span class="text-muted"> · {{ user.privilege }}<template v-if="user.expiresAt"> · <Rich :text="t('expires :when')"><template #when><RelativeTime :at="user.expiresAt" /></template></Rich></template></span>
                            </p>
                            <p v-if="user.status === 'failed'" class="text-xs text-danger">{{ user.error }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <AcmeBadge :tone="acmeTone(userStatuses[user.status]?.tone ?? 'neutral')">{{ userStatuses[user.status]?.label ?? user.status }}</AcmeBadge>
                            <ApiForm v-if="page.canManage && user.status !== 'removing'" :action="`${base}/database/users/${user.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                        </div>
                    </li>
                </ul>
                <FormDialog
                    v-if="page.canManageDatabase"
                    id="add-database-user"
                    :title="t('Add a database user')"
                    :description="t('For reporting tools and one-off access. The password is shown once; the user can expire by itself.')"
                    :action="`${base}/database/users`"
                    :submit="t('Add user')"
                    size="wide"
                >
                    <template #trigger="{ open }"><div><AcmeBtn size="sm" icon="plus" @click="open">{{ t('Add a database user') }}</AcmeBtn></div></template>
                    <div class="grid items-start gap-4 sm:grid-cols-3">
                        <InputField id="database-username" name="username" :label="t('Username')" placeholder="reporting" maxlength="32" required autofocus />
                        <SelectField id="database-privilege" name="privilege" :label="t('Access')" :options="privileges" />
                        <SelectField id="database-expiry" v-model="expiry" name="expires_in_days" :label="t('Expires')" :placeholder="t('Never')" :options="expiries" />
                    </div>
                </FormDialog>
            </div>

            <div v-if="page.canManageDatabase || page.copies.length > 0" class="grid gap-3 border-t border-line pt-5">
                <h3 class="font-bold text-ink">{{ t('Copy into another website') }}</h3>
                <p v-for="copy in page.copies" :key="copy.id" class="text-sm">
                    <span class="text-muted">{{ copy.createdAt ? dateTime(copy.createdAt) : '' }}</span> · {{ copy.source }} → {{ copy.target }} · {{ copyStatuses[copy.status] ?? copy.status }}
                    <template v-if="copy.error"> · <span class="text-danger">{{ copy.error }}</span></template>
                </p>
                <template v-if="page.canManageDatabase">
                    <p v-if="page.copyTargets.length === 0" class="text-sm text-muted">{{ t('No other websites on this server.') }}</p>
                    <ApiForm v-else :action="`${base}/database/copy`" class="grid items-start gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                        <SelectField id="copy-target" name="target_website_id" :label="t('Overwrite the database of')" :options="targets" />
                        <InputField id="copy-confirmation" name="confirmation" :label="t('Type its name to confirm')" autocomplete="off" maxlength="120" required />
                        <div class="sm:col-span-2">
                            <CheckboxField
                                id="copy-anonymise"
                                name="anonymise"
                                :label="t('Mask personal data')"
                                checked
                                :description="t('Replace emails, names, phone numbers, addresses, IP addresses and dates of birth in the copy, by column name. Recommended for staging.')"
                            />
                        </div>
                        <p class="text-xs text-muted sm:col-span-2">{{ t('Every table in the chosen website’s database is replaced with a copy of this one. This can’t be undone.') }}</p>
                        <div class="sm:col-span-2"><SubmitButton variant="danger">{{ t('Copy database') }}</SubmitButton></div>
                    </ApiForm>
                </template>
            </div>
        </div>
    </AcmeCard>
</template>
