<script setup lang="ts">
import type { DomainRow, ProjectOverview } from '~/types/projects';

/** The hostnames a project serves, each verified with a TXT record so no other account can claim it. */
definePageMeta({ layout: 'app' });
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; domains: DomainRow[] }>(() => `/projects/${route.params.project}/domains`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/domains`);
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
const checking = ref<string | null>(null);
const problems = ref<Record<string, string>>({});

/** Look for the domain's TXT record now, and say what was found. */
async function verify(domain: DomainRow) {
    checking.value = domain.id;
    problems.value = { ...problems.value, [domain.id]: '' };
    try {
        const result = await send<{ message: string }>('POST', `/projects/${project.value.id}/domains/${domain.id}/verify`);
        flash(result.message, 'info');
        await refreshPage();
    } catch (problem) {
        problems.value = { ...problems.value, [domain.id]: problem instanceof ValidationError ? (problem.first('domain') ?? problem.message) : t('Something went wrong. Try again.') };
    }
    checking.value = null;
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Domains')" :description="t('Hostnames this project serves. Verify each one so no other account can claim it.')">
            <template v-if="data.overview.canManage && data.domains.length > 0" #actions>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-domain' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Add domain') }}</UiButton>
            </template>
        </ProjectHeader>

        <EmptyState v-if="data.domains.length === 0" icon="globe" :title="t('No domains yet')" :description="t('Add the hostnames this project serves, such as example.com and www.example.com.')">
            <template v-if="data.overview.canManage" #action>
                <UiButton variant="primary" :to="{ query: { dialog: 'add-domain' } }">{{ t('Add a domain') }}</UiButton>
            </template>
        </EmptyState>

        <ul v-else class="grid gap-4">
            <li v-for="domain in data.domains" :key="domain.id" class="ui-card grid gap-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-base font-extrabold text-ink">
                            <span class="break-all">{{ domain.name }}</span>
                            <Badge :tone="domain.verifiedAt ? 'success' : 'warning'">{{ domain.verifiedAt ? t('Verified') : t('Not verified') }}</Badge>
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            {{ domain.environment ?? t('Any environment') }}
                            <template v-if="domain.verifiedAt"> · {{ t('Verified :time', { time: dateTime(domain.verifiedAt) }) }}</template>
                            <template v-else-if="domain.lastCheckedAt"> · {{ t('Last checked :time', { time: dateTime(domain.lastCheckedAt) }) }}</template>
                        </p>
                    </div>
                    <div v-if="data.overview.canManage" class="flex flex-wrap gap-2">
                        <UiButton v-if="!domain.verifiedAt" size="sm" :disabled="checking === domain.id" :aria-busy="checking === domain.id || undefined" @click="verify(domain)">
                            {{ checking === domain.id ? t('Working…') : t('Check DNS') }}
                        </UiButton>
                        <DeleteDialog
                            :id="`remove-domain-${domain.id}`"
                            :title="t('Remove :domain?', { domain: domain.name })"
                            :action="`${base}/${domain.id}`"
                            :warning="t('The project stops claiming this hostname. Services stop serving it.')"
                            :submit-label="t('Remove')"
                        >
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Remove') }}</UiButton></template>
                        </DeleteDialog>
                    </div>
                </div>
                <Alert v-if="problems[domain.id]" tone="danger" role="alert">{{ problems[domain.id] }}</Alert>
                <div v-if="!domain.verifiedAt" class="grid gap-3 rounded-card bg-surface-muted p-4">
                    <p class="text-sm font-bold text-ink">{{ t('Add this TXT record at your DNS provider') }}</p>
                    <dl class="grid gap-2 text-sm sm:grid-cols-[6rem_minmax(0,1fr)]">
                        <dt class="font-semibold text-muted">{{ t('Name') }}</dt>
                        <dd><code class="break-all">{{ domain.recordName }}</code></dd>
                        <dt class="font-semibold text-muted">{{ t('Value') }}</dt>
                        <dd><code class="break-all">{{ domain.recordValue }}</code></dd>
                    </dl>
                </div>
            </li>
        </ul>

        <FormDialog v-if="data.overview.canManage" id="add-domain" :title="t('Add a domain')" :action="base" :submit="t('Add domain')">
            <InputField name="hostname" :label="t('Hostname')" :placeholder="t('shop.example.com')" maxlength="300" autocomplete="off" required autofocus />
            <SelectField name="environment_id" :label="t('Environment')" :placeholder="t('Any environment')" :options="environments" />
        </FormDialog>
    </div>
</template>
