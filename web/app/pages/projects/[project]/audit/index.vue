<script setup lang="ts">
import type { AuditListItem, AuditPlan, Goal } from '~/types/audit';

/** The project's audits: each site with its latest score, how much of the month is used, and setting one up (a wizard). */
definePageMeta({ layout: 'app', service: 'audit' });
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<{ audits: AuditListItem[]; plan: AuditPlan; goals: Goal[] }>(() => `/projects/${route.params.project}/audit`);
const projectId = computed(() => String(route.params.project));
const canAdd = computed(() => data.value.plan.canManage && (data.value.plan.auditLimit === null || data.value.audits.length < data.value.plan.auditLimit));
useHead({ title: () => t('Audits') });
</script>

<template>
    <div class="space-y-6">
        <PageHeader icon="search" eyebrow="Audit" :title="t('Audits')" :description="t('Watch a visitor use your site and your competitors’, and see what to improve.')">
            <template v-if="canAdd" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'new-audit' } }" icon="plus">{{ t('New audit') }}</AcmeBtn>
            </template>
        </PageHeader>
        <p class="text-xs font-semibold text-muted">
            {{ data.plan.runsAllowance === null ? tc(':count audit run this month|:count audits run this month', data.plan.runsUsed, { count: data.plan.runsUsed }) : t(':used of :allowance audits used this month', { used: data.plan.runsUsed, allowance: data.plan.runsAllowance }) }}
            <template v-if="data.plan.tier"> · {{ t(':tier plan', { tier: data.plan.tier }) }}</template>
        </p>

        <EmptyState v-if="data.audits.length === 0" icon="search" :title="t('No audits yet')" :description="t('Add your site and a few competitors. A visitor tries real tasks on each, and the report shows what to improve first.')">
            <template #action><AcmeBtn v-if="data.plan.canManage" variant="primary" :to="{ query: { dialog: 'new-audit' } }">{{ t('Set up your first audit') }}</AcmeBtn></template>
        </EmptyState>
        <ul v-else class="grid gap-3 md:grid-cols-2">
            <li v-for="audit in data.audits" :key="audit.id">
                <NuxtLink :to="`/projects/${projectId}/audit/${audit.id}`" class="rounded-2xl border border-line bg-surface shadow-card transition hover:-translate-y-0.5 hover:shadow-lift flex h-full flex-col gap-4 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-semibold text-ink">{{ audit.name }}</h2>
                            <p class="truncate text-sm text-muted">{{ audit.url }}</p>
                        </div>
                        <RunBadge v-if="audit.latestRun" :run="audit.latestRun" />
                    </div>
                    <p class="mt-auto flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-muted">
                        <span>{{ tc(':count competitor|:count competitors', audit.competitors, { count: audit.competitors }) }}</span>
                        <span>{{ audit.latestRun ? t('Last run :date', { date: dateTime(audit.latestRun.createdAt) }) : t('Not run yet') }}</span>
                    </p>
                </NuxtLink>
            </li>
        </ul>

        <UiDialog v-if="canAdd" id="new-audit" :title="t('New audit')" size="wide">
            <template #default="{ close }"><AuditWizard :project-id="projectId" :plan="data.plan" :goals="data.goals" @done="close" /></template>
        </UiDialog>
    </div>
</template>
