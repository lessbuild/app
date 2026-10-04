<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/** Recipes from the account's library that run, in order, on the servers an environment's websites are on. */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t } = useT();
const recipe = ref<string | null>(props.page.libraryRecipes[0]?.value ?? null);
</script>

<template>
    <SettingsSection
        id="recipes"
        :title="t('Recipes')"
        :description="t('Scripts from your recipe library that run in this order, as root, on the servers this environment’s websites are on. Each run is in the server’s command history.')"
    >
        <div class="grid gap-4 p-4 sm:p-6">
            <ol v-if="page.recipes.length > 0" class="grid gap-2">
                <li v-for="(entry, index) in page.recipes" :key="entry.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-mono text-xs text-muted">{{ index + 1 }}.</span> <span class="font-bold">{{ entry.name }}</span>
                        <span v-if="entry.deleted" class="text-xs text-muted"> · {{ t('library recipe deleted') }}</span>
                        <Badge v-else-if="entry.behind" tone="warning" class="ml-2">{{ t('Library has changes') }}</Badge>
                    </span>
                    <div v-if="page.canManage" class="flex flex-wrap gap-1">
                        <ApiForm v-if="index > 0" :action="`${base}/recipes/${entry.id}/move`">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="ui-icon-btn" :aria-label="t('Move :name up', { name: entry.name })"><Icon name="chevron-down" class="h-4 w-4 rotate-180" /></button>
                        </ApiForm>
                        <ApiForm v-if="index < page.recipes.length - 1" :action="`${base}/recipes/${entry.id}/move`">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="ui-icon-btn" :aria-label="t('Move :name down', { name: entry.name })"><Icon name="chevron-down" class="h-4 w-4" /></button>
                        </ApiForm>
                        <ApiForm v-if="entry.behind" :action="`${base}/recipes/${entry.id}/refresh`"><SubmitButton variant="secondary" size="sm">{{ t('Update') }}</SubmitButton></ApiForm>
                        <ApiForm :action="`${base}/recipes/${entry.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                    </div>
                </li>
            </ol>
            <p v-else class="text-sm text-muted">{{ t('No recipes on this environment yet.') }}</p>
            <template v-if="page.canManage">
                <p v-if="page.libraryRecipes.length === 0" class="text-sm text-muted">
                    {{ t('Write a recipe under Account → Recipes, or install one from the gallery, then add it here.') }}
                    <NuxtLink to="/account/recipes" class="font-bold text-primary underline">{{ t('Recipes') }}</NuxtLink>
                </p>
                <ApiForm v-else :action="`${base}/recipes`" class="flex flex-wrap items-end gap-3 rounded-panel border border-line bg-surface-muted p-4">
                    <SelectField v-model="recipe" name="recipe_id" :label="t('Add a recipe')" :options="page.libraryRecipes" />
                    <SubmitButton variant="secondary">{{ t('Add') }}</SubmitButton>
                </ApiForm>
                <ApiForm :action="`${base}/recipes/settings`" method="PUT" class="flex flex-wrap items-center gap-3">
                    <CheckboxField name="run_on_new_websites" :label="t('Run them when a new website for this environment finishes setting up')" :checked="page.environment.recipesRunOnNewWebsites" />
                    <SubmitButton variant="quiet" size="sm">{{ t('Save') }}</SubmitButton>
                </ApiForm>
                <ApiForm v-if="page.recipes.length > 0" :action="`${base}/recipes/run`" :confirm="t('Run these recipes on the servers now?')">
                    <SubmitButton>{{ t('Run on servers now') }}</SubmitButton>
                </ApiForm>
            </template>
        </div>
    </SettingsSection>
</template>
