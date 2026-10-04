<script setup lang="ts">
import type { Option } from '~/types/ui';

/** Recipes other accounts published: search, filter by category, sort, and show favourites. */
definePageMeta({ layout: 'app', area: 'account' });
type GalleryRecipe = { id: number; name: string; category: string; publisher: string; description: string | null; installs: number; ratings: number; average: number | null; installed: boolean; favorited: boolean };
type Gallery = { recipes: GalleryRecipe[]; page: number; lastPage: number; filters: { q: string; category: string; sort: string; favorites: boolean }; categories: Option[]; canSeeReports: boolean };
const { t, tc, number } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<Gallery>('/recipes/gallery', () => ({ q: text(route.query.q), category: text(route.query.category), sort: text(route.query.sort), favorites: text(route.query.favorites), page: text(route.query.page) }));
const q = ref(data.value.filters.q);
const category = ref<string | null>(data.value.filters.category);
const sort = ref<string | null>(data.value.filters.sort || 'popular');
const favorites = ref(data.value.filters.favorites);
const sorts = computed(() => [{ value: 'popular', label: t('Most installed') }, { value: 'rating', label: t('Best rated') }, { value: 'newest', label: t('Newest') }]);

/** Show the gallery with the chosen filters, from the first page. */
function show() {
    navigateTo({ query: { q: q.value || undefined, category: category.value || undefined, sort: sort.value === 'popular' ? undefined : (sort.value ?? undefined), favorites: favorites.value ? '1' : undefined } });
}
watch([category, sort, favorites], show);
const page = (to: number) => ({ query: { ...route.query, page: to > 1 ? String(to) : undefined } });
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('Recipe gallery')" :description="t('Recipes other accounts have published. Read a script before you install it: it runs as root on your servers.')" />
        <RecipeNav :reports="data.canSeeReports" />

        <form class="flex flex-wrap items-end gap-3" role="search" @submit.prevent="show">
            <InputField v-model="q" name="q" :label="t('Search')" placeholder="redis" maxlength="100" />
            <SelectField v-model="category" name="category" :label="t('Category')" :placeholder="t('All')" :options="data.categories" />
            <SelectField v-model="sort" name="sort" :label="t('Sort by')" :options="sorts" />
            <label class="inline-flex min-h-10 items-center gap-2 text-sm font-semibold text-ink">
                <input v-model="favorites" type="checkbox" class="h-4 w-4 rounded border-line accent-[var(--ui-primary)]">{{ t('Favourites only') }}
            </label>
            <UiButton type="submit">{{ t('Show') }}</UiButton>
        </form>

        <EmptyState v-if="data.recipes.length === 0" icon="code" :title="t('No recipes found')" :description="t('Try another search or category.')" />
        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="recipe in data.recipes" :key="recipe.id" class="ui-card flex flex-col gap-2 p-5">
                <NuxtLink :to="`/recipes/gallery/${recipe.id}`" class="font-extrabold text-ink hover:underline">{{ recipe.name }}</NuxtLink>
                <p class="text-xs text-muted">{{ recipe.category }} · {{ t('by :account', { account: recipe.publisher }) }}</p>
                <p v-if="recipe.description" class="text-sm">{{ recipe.description.slice(0, 140) }}</p>
                <p class="mt-auto text-xs text-muted">
                    {{ tc(':count install|:count installs', recipe.installs, { count: recipe.installs }) }} ·
                    {{ recipe.ratings > 0 ? t(':average★ from :count', { average: number(recipe.average ?? 0), count: recipe.ratings }) : t('Not rated yet') }}
                    <template v-if="recipe.installed"> · <span class="text-success">{{ t('Installed') }}</span></template>
                    <template v-if="recipe.favorited"> · {{ t('Favourite') }}</template>
                </p>
            </article>
        </div>
        <nav v-if="data.lastPage > 1" class="flex justify-between" :aria-label="t('Pages')">
            <UiButton :to="page(data.page - 1)" :class="data.page <= 1 && 'pointer-events-none opacity-50'" :aria-disabled="data.page <= 1 || undefined">{{ t('Previous') }}</UiButton>
            <span class="self-center text-sm text-muted">{{ t('Page :page of :pages', { page: data.page, pages: data.lastPage }) }}</span>
            <UiButton :to="page(data.page + 1)" :class="data.page >= data.lastPage && 'pointer-events-none opacity-50'" :aria-disabled="data.page >= data.lastPage || undefined">{{ t('Next') }}</UiButton>
        </nav>
    </div>
</template>
