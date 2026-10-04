<script setup lang="ts">
/** What people reported about the recipes this account published; resolving one tells the reporter, with a note. */
definePageMeta({ layout: 'app', area: 'account' });
type Report = { id: number; recipe: string; reason: string; reporter: string; details: string | null; status: string; resolver: string | null; note: string | null; updatedAt: string | null };
const { t } = useT();
const { data } = await useApi<{ reports: Report[] }>('/account/recipes/reports');
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Reports on our recipes')" :description="t('What people reported about the recipes this account published. Resolving one tells the reporter, with your note.')" />
        <RecipeNav reports />
        <EmptyState v-if="data.reports.length === 0" icon="check" :title="t('No reports')" :description="t('Nobody has reported this account’s recipes.')" />
        <section v-for="report in data.reports" :key="report.id" class="ui-card grid gap-3 p-5 text-sm" :aria-label="`${report.recipe} · ${report.reason}`">
            <div>
                <h2 class="font-extrabold text-ink">{{ report.recipe }} · {{ report.reason }}</h2>
                <p class="text-xs text-muted">
                    <Rich :text="t('From :name, :when', { name: report.reporter })"><template #when><RelativeTime v-if="report.updatedAt" :at="report.updatedAt" /></template></Rich>
                </p>
            </div>
            <p v-if="report.details" class="whitespace-pre-line">{{ report.details }}</p>
            <template v-if="report.status === 'resolved'">
                <p class="text-muted"><Badge tone="success">{{ t('Resolved') }}</Badge> {{ t('by :name', { name: report.resolver ?? t('someone') }) }}<template v-if="report.note"> · {{ report.note }}</template></p>
                <ApiForm :action="`/api/app/account/recipes/reports/${report.id}`" method="PUT">
                    <input type="hidden" name="resolved" value="0">
                    <SubmitButton variant="quiet" size="sm">{{ t('Reopen') }}</SubmitButton>
                </ApiForm>
            </template>
            <ApiForm v-else :action="`/api/app/account/recipes/reports/${report.id}`" method="PUT" class="grid gap-3">
                <input type="hidden" name="resolved" value="1">
                <TextareaField :id="`note-${report.id}`" name="resolution_note" :label="t('Note for the reporter (optional)')" rows="2" maxlength="2000" />
                <div><SubmitButton variant="secondary" size="sm">{{ t('Resolve') }}</SubmitButton></div>
            </ApiForm>
        </section>
    </div>
</template>
