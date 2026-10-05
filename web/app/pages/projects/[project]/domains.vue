<script setup lang="ts">
import type { DomainRow, ProjectOverview } from '~/types/projects';

/**
 * The hostnames a project serves (the Acme theme's domains page), each verified with a TXT record so no other account
 * can claim it.
 */
definePageMeta({ layout: 'app' });
const { t, tc, dateTime } = useT();
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

const verified = computed(() => data.value.domains.filter((domain) => domain.verifiedAt).length);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Domains')" :description="t('Hostnames this project serves. Verify each one so no other account can claim it.')" />
        <div class="space-y-6">
            <AcmeCard v-if="data.overview.canManage">
                <ApiForm :action="base" class="!grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-start">
                    <InputField id="domain-hostname" name="hostname" :label="t('Add a domain')" :placeholder="t('shop.example.com')" maxlength="300" autocomplete="off" required />
                    <SelectField id="domain-environment" name="environment_id" :label="t('Environment')" :placeholder="t('Any environment')" :options="environments" />
                    <SubmitButton class="sm:mt-7">{{ t('Add domain') }}</SubmitButton>
                </ApiForm>
            </AcmeCard>

            <AcmeEmptyCard v-if="data.domains.length === 0" icon="globe" :title="t('No domains yet')" :description="t('Add the hostnames this project serves, such as example.com and www.example.com.')" />
            <section v-else aria-labelledby="domains-title">
                <h2 id="domains-title" class="section-label mb-3">{{ tc(':count domain|:count domains', data.domains.length) }} · {{ t(':count verified', { count: verified }) }}</h2>
                <ul class="space-y-3">
                    <li v-for="domain in data.domains" :key="domain.id" class="rounded-2xl border border-line bg-surface p-5 shadow-card">
                        <div class="flex flex-wrap items-center gap-3">
                            <span :class="['grid size-9 place-items-center rounded-xl', domain.verifiedAt ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600']" aria-hidden="true"><AcmeIcon :name="domain.verifiedAt ? 'lock' : 'alert'" :size="17" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-sm font-medium text-ink">{{ domain.name }}</span>
                                <span class="text-xs text-muted">{{ domain.environment ?? t('Any environment') }} · {{ domain.verifiedAt ? t('Verified :time', { time: dateTime(domain.verifiedAt) }) : domain.lastCheckedAt ? t('Last checked :time', { time: dateTime(domain.lastCheckedAt) }) : t('Not checked yet') }}</span>
                            </span>
                            <AcmeBadge :tone="domain.verifiedAt ? 'green' : 'amber'">{{ domain.verifiedAt ? t('Verified') : t('Not verified') }}</AcmeBadge>
                            <template v-if="data.overview.canManage">
                                <AcmeBtn v-if="!domain.verifiedAt" size="sm" icon="refresh" :loading="checking === domain.id" @click="verify(domain)">{{ t('Check DNS') }}</AcmeBtn>
                                <DeleteDialog :id="`remove-domain-${domain.id}`" :title="t('Remove :domain?', { domain: domain.name })" :action="`${base}/${domain.id}`" :warning="t('The project stops claiming this hostname. Services stop serving it.')" :submit-label="t('Remove')">
                                    <template #trigger="{ open }"><AcmeBtn size="sm" variant="ghost" icon="trash" :label="t('Remove :domain', { domain: domain.name })" @click="open" /></template>
                                </DeleteDialog>
                            </template>
                        </div>
                        <AcmeAlert v-if="problems[domain.id]" tone="danger" class="mt-4">{{ problems[domain.id] }}</AcmeAlert>
                        <div v-if="!domain.verifiedAt" class="mt-4 rounded-xl bg-black/[.03] p-4 dark:bg-white/[.04]">
                            <p class="text-sm font-medium text-ink">{{ t('Add this TXT record at your DNS provider') }}</p>
                            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <div v-for="[label, value] in [[t('Name'), domain.recordName], [t('Value'), domain.recordValue]]" :key="label" class="flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-1.5">
                                    <dt class="w-12 shrink-0 text-xs text-muted">{{ label }}</dt>
                                    <dd class="min-w-0 flex-1 truncate font-mono text-xs text-ink">{{ value }}</dd>
                                    <AcmeBtn size="sm" variant="ghost" icon="copy" :label="t('Copy :what', { what: label })" @click="copyText(value!)" />
                                </div>
                            </dl>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
