<script setup lang="ts">
/** The gallery recipes the person reported, and what their publishers said. */
definePageMeta({ layout: 'app', area: 'account' });
type MyReport = { id: number; recipeId: number; recipe: string; published: boolean; reason: string; status: string; note: string | null };
const { t } = useT();
const { data } = await useApi<{ reports: MyReport[]; canSeeReports: boolean }>('/recipes/gallery/reports');
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Your reports')" :description="t('Recipes you reported, and what their publishers said.')" />
        <RecipeNav :reports="data.canSeeReports" />
        <EmptyState v-if="data.reports.length === 0" icon="check" :title="t('No reports')" :description="t('Report a recipe from its gallery page.')" />
        <DataTable v-else :caption="t('Your reports')">
            <template #head>
                <tr><th scope="col">{{ t('Recipe') }}</th><th scope="col">{{ t('Reason') }}</th><th scope="col">{{ t('Status') }}</th></tr>
            </template>
            <tr v-for="report in data.reports" :key="report.id">
                <td>
                    <NuxtLink v-if="report.published" :to="`/recipes/gallery/${report.recipeId}`" class="font-bold text-primary hover:underline">{{ report.recipe }}</NuxtLink>
                    <template v-else>{{ report.recipe }} <span class="text-xs text-muted">{{ t('(no longer published)') }}</span></template>
                </td>
                <td>{{ report.reason }}</td>
                <td>
                    <Badge :tone="report.status === 'resolved' ? 'success' : 'warning'">{{ report.status === 'resolved' ? t('Resolved') : t('Open') }}</Badge>
                    <span v-if="report.note" class="block text-xs text-muted">{{ report.note }}</span>
                </td>
            </tr>
        </DataTable>
    </div>
</template>
