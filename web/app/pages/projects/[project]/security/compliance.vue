<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Evidence for SOC 2, ISO 27001 and security questionnaires: how the project stands, and the evidence pack to download. */
definePageMeta({ layout: 'app', service: 'security' });
type CompliancePage = { overview: ProjectOverview; lastReviewAt: string | null; openSerious: number; resolved: number; included: boolean; canManage: boolean };
const { t, number, date } = useT();
const route = useRoute();
const { data } = await useApi<CompliancePage>(() => `/projects/${route.params.project}/security/compliance`);
const project = computed(() => data.value.overview.project);
const months = computed(() => [{ value: '3', label: t('The last 3 months') }, { value: '6', label: t('The last 6 months') }, { value: '12', label: t('The last 12 months') }]);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Evidence for SOC 2, ISO 27001 and security questionnaires, from what BuildPusher already records.')" />
        <PlanNotice v-if="!data.included" :message="t('Compliance reports come with the Team Security plan.')" />

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard :label="t('Last access review')" :value="data.lastReviewAt ? date(data.lastReviewAt) : t('Never')" />
            <StatCard :label="t('Open critical and high findings')" :value="number(data.openSerious)" />
            <StatCard :label="t('Findings resolved this year')" :value="number(data.resolved)" />
        </div>

        <AcmeCard :padded="false" :title="t('Evidence pack')" :description="t('A ZIP of spreadsheets (CSV) with a README that maps each to the SOC 2 and ISO 27001 controls it supports: members and two-factor, API tokens, access reviews, every deploy and who approved it, vulnerabilities and how each was handled, scans, patching, blocked attacks, backups and restore tests, incidents, and the audit log.')">
            <section class="ui-card p-5 sm:p-6">
                <h2 class="sr-only">{{ t('Evidence pack') }}</h2>
                <ApiForm v-if="data.canManage && data.included" :action="`/api/app/projects/${project.id}/security/compliance/evidence`" class="flex flex-wrap items-end gap-3">
                    <SelectField id="evidence-months" name="months" :label="t('Covering')" :options="months" model-value="12" />
                    <SubmitButton><Icon name="arrow-down" class="h-4 w-4" />{{ t('Download evidence') }}</SubmitButton>
                </ApiForm>
                <p v-else class="text-sm text-muted">{{ data.included ? t('Only people who manage Security can download the evidence pack.') : t('Upgrade to download the evidence pack.') }}</p>
            </section>
        </AcmeCard>
    </div>
</template>
