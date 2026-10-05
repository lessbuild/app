<script setup lang="ts">
import type { Option } from '~/types/ui';

/** The account's recipes: scripts that run on new servers at the end of provisioning, its own or from the gallery. */
definePageMeta({ layout: 'app', area: 'account' });
type RecipeRow = { id: number; name: string; category: string; description: string | null; published: boolean; installs: number; fromGallery: boolean; updateAvailable: boolean };
const { t, tc } = useT();
const { data } = await useApi<{ account: { id: string; name: string }; recipes: RecipeRow[]; categories: Option[]; canCreate: boolean; canSeeReports: boolean }>('/account/recipes');
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Recipes')" :description="t('Scripts that run on new servers at the end of provisioning. Choose them when you create a server, or install one from the gallery.')">
            <template #actions>
                <a href="/api/app/account/inventory/recipes.csv" class="ui-btn ui-btn-quiet ui-btn-sm" download>{{ t('Export CSV') }}</a>
                <AcmeBtn v-if="data.canCreate" variant="primary" :to="{ query: { dialog: 'new-recipe' } }" icon="plus">{{ t('New recipe') }}</AcmeBtn>
            </template>
        </PageHeader>
        <RecipeNav :reports="data.canSeeReports" />

        <EmptyState v-if="data.recipes.length === 0" icon="code" :title="t('No recipes yet')" :description="t('Write one, or install one from the gallery.')">
            <template #action><AcmeBtn to="/recipes/gallery">{{ t('Gallery') }}</AcmeBtn></template>
        </EmptyState>
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Recipes')">
                <li v-for="recipe in data.recipes" :key="recipe.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/account/recipes/${recipe.id}`" class="font-semibold text-ink hover:underline">{{ recipe.name }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">{{ recipe.category }}<template v-if="recipe.description"> · {{ recipe.description.slice(0, 100) }}</template></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AcmeBadge v-if="recipe.published" tone="blue">{{ tc('In the gallery · :count install|In the gallery · :count installs', recipe.installs, { count: recipe.installs }) }}</AcmeBadge>
                        <AcmeBadge v-if="recipe.fromGallery">{{ t('From the gallery') }}</AcmeBadge>
                        <AcmeBadge v-if="recipe.updateAvailable" tone="amber">{{ t('Update available') }}</AcmeBadge>
                    </div>
                </li>
            </ul>
        </section>

        <FormDialog v-if="data.canCreate" id="new-recipe" :title="t('New recipe')" :description="t('Each save keeps a revision, so you can see what changed and who changed it.')" action="/api/app/account/recipes" :submit="t('Save recipe')" size="large">
            <RecipeFields :recipe="null" :categories="data.categories" />
        </FormDialog>
    </div>
</template>
