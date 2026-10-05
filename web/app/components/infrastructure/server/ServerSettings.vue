<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** A server's settings: snapshots before risky changes, its Node.js version, its display name, and deleting it. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const server = computed(() => props.page.server);
const node = ref<string | null>(server.value.nodeVersion ?? props.page.nodeVersions.at(-1) ?? null);
const nodeVersions = computed(() => props.page.nodeVersions.map((version) => ({ value: version, label: `Node.js ${version}` })));
const active = computed(() => server.value.status === 'active');
</script>

<template>
    <div class="space-y-6">
        <AcmeCard
v-if="server.provider !== null && active"
            id="snapshots"
            :padded="false"
            :title="t('Snapshots before risky changes')"
            :description="t('Take a provider snapshot before updates are installed, security fixes run or the Node.js version changes, keeping the three newest. Restore one from your provider’s dashboard if a change goes wrong. Your provider charges for snapshot storage.')"
        >
            <div class="grid gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <ApiForm :action="`${base}/snapshots`" method="PUT" class="flex flex-wrap items-center gap-3">
                    <CheckboxField name="snapshot_before_changes" unchecked-value="0" :label="t('Snapshot before risky changes')" :checked="server.snapshotBeforeChanges" />
                    <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                </ApiForm>
                <p v-for="snapshot in page.snapshots" :key="snapshot.id" class="flex flex-wrap items-center gap-2 text-sm">
                    <AcmeBadge :tone="acmeTone(snapshot.status === 'taken' ? 'success' : 'danger')">{{ snapshot.status === 'taken' ? t('Taken') : t('Failed') }}</AcmeBadge>
                    <span class="text-muted">{{ snapshot.reason }}<template v-if="snapshot.createdAt"> · <RelativeTime :at="snapshot.createdAt" /></template></span>
                    <span v-if="snapshot.error" class="text-xs text-danger">{{ snapshot.error }}</span>
                </p>
                <ApiForm :action="`${base}/snapshots`"><SubmitButton variant="quiet" size="sm">{{ t('Take a snapshot now') }}</SubmitButton></ApiForm>
            </div>
        </AcmeCard>
        <AcmeCard v-if="server.installsNode && active" id="node-version" :padded="false" :title="t('Node.js version')" :description="t('Node.js is installed for the whole server, so switching changes it for every website and build on it.')">
            <ApiForm :action="`${base}/node-version`" method="PUT" class="flex flex-wrap items-end gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <SelectField v-model="node" name="node_version" :label="t('Node.js')" :options="nodeVersions" />
                <SubmitButton variant="secondary">{{ t('Switch') }}</SubmitButton>
            </ApiForm>
        </AcmeCard>
        <AcmeCard :padded="false" :title="t('Name')" :description="t('Shown in the app. The server’s hostname stays :name.', { name: server.name })">
            <ApiForm :action="base" method="PUT" class="flex flex-wrap items-end gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
                <InputField name="display_name" :label="t('Display name')" :model-value="server.displayName" maxlength="80" />
                <SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton>
            </ApiForm>
        </AcmeCard>
        <section v-if="page.canDelete" class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-rose-500/30 bg-rose-500/[.03] p-5">
            <span>
                <b class="block font-semibold text-rose-700 dark:text-rose-300">{{ t('Delete this server') }}</b>
                <span class="text-sm text-muted">{{ server.provider ? t(':provider deletes the machine and everything on it, and the SSH key we made.', { provider: server.provider }) : t('The server is forgotten here; nothing on it is changed.') }}</span>
            </span>
            <DeleteDialog
                id="delete-server"
                :title="t('Delete :server?', { server: server.label })"
                :description="server.provider ? t('The machine and its data are deleted at the provider. This can’t be undone.') : t('Nothing on the server is changed.')"
                :action="base"
                :submit-label="t('Delete server')"
            >
                <template #trigger="{ open }"><AcmeBtn variant="danger" @click="open">{{ t('Delete server') }}</AcmeBtn></template>
            </DeleteDialog>
        </section>
    </div>
</template>
