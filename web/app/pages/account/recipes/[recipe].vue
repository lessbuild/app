<script setup lang="ts">
import type { Option } from '~/types/ui';

/** One of the account's recipes: its script and history, the gallery, and any newer gallery version of it. */
definePageMeta({ layout: 'app', area: 'account' });
type RecipePage = {
    recipe: {
        id: number;
        name: string;
        category: string;
        description: string | null;
        script: string;
        published: boolean;
        installs: number;
        source: { id: number; name: string; script: string } | null;
        fromGallery: boolean;
        updateAvailable: boolean;
    };
    revisions: Array<{ id: number; change: string; user: string | null; name: string; description: string | null; script: string; createdAt: string | null }>;
    keepRevisions: number;
    categories: Option[];
    openReports: number;
    canUpdate: boolean;
    canPublish: boolean;
    canSeeReports: boolean;
};
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<RecipePage>(() => `/account/recipes/${route.params.recipe}`);
const recipe = computed(() => data.value.recipe);
const base = computed(() => `/api/app/account/recipes/${recipe.value.id}`);
const changes = computed<Record<string, string>>(() => ({ created: t('Created'), edited: t('Edited'), installed: t('Installed from the gallery'), refreshed: t('Updated from the gallery'), duplicated: t('Copied') }));
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="recipe.name" :description="recipe.description ?? t('A recipe that runs on new servers.')" />
        <RecipeNav :reports="data.canSeeReports" />

        <section v-if="recipe.updateAvailable" class="ui-panel space-y-3 border-warning p-6" aria-labelledby="update-heading">
            <h2 id="update-heading" class="text-lg font-semibold text-ink">{{ t('The gallery has a newer version') }}</h2>
            <p class="text-sm text-muted">{{ t('Updating replaces this recipe’s name, description and script. Your current version stays in the history.') }}</p>
            <Disclosure :title="t('See the gallery version')"><CodeBlock :code="recipe.source?.script ?? ''" class="max-h-96 overflow-auto text-xs" /></Disclosure>
            <ApiForm v-if="data.canUpdate" :action="`${base}/refresh`"><SubmitButton>{{ t('Update from the gallery') }}</SubmitButton></ApiForm>
        </section>

        <AcmeCard :padded="false" :title="t('Gallery')" :description="t('Publishing shares this recipe’s script with every account. Owners and admins decide.')">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm sm:p-6">
                <p class="flex flex-wrap items-center gap-2">
                    <template v-if="recipe.published">
                        <AcmeBadge tone="blue">{{ t('Published') }}</AcmeBadge>
                        <span class="text-muted">{{ tc(':count install|:count installs', recipe.installs, { count: recipe.installs }) }}</span>
                        <NuxtLink v-if="data.openReports > 0" to="/account/recipes/reports" class="text-danger hover:underline">{{ tc(':count open report|:count open reports', data.openReports, { count: data.openReports }) }}</NuxtLink>
                        <NuxtLink :to="`/recipes/gallery/${recipe.id}`" class="text-primary hover:underline">{{ t('View in the gallery') }}</NuxtLink>
                    </template>
                    <span v-else-if="recipe.fromGallery" class="text-muted">
                        {{ t('Installed from the gallery') }}<template v-if="recipe.source"> · <NuxtLink :to="`/recipes/gallery/${recipe.source.id}`" class="text-primary hover:underline">{{ recipe.source.name }}</NuxtLink></template>
                    </span>
                    <span v-else class="text-muted">{{ t('Only your account sees it.') }}</span>
                </p>
                <ApiForm v-if="data.canPublish" :action="`${base}/publication`" method="PUT">
                    <input type="hidden" name="published" :value="recipe.published ? '0' : '1'">
                    <SubmitButton :variant="recipe.published ? 'quiet' : 'secondary'" size="sm">{{ recipe.published ? t('Take out of the gallery') : t('Publish to the gallery') }}</SubmitButton>
                </ApiForm>
            </div>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('Script')" :description="data.canUpdate ? t('Saving keeps a revision. Servers created earlier keep the version they ran.') : t('Runs as root at the end of a new server’s provisioning.')">
            <template v-if="data.canUpdate">
                <ApiForm :action="base" method="PUT" class="grid gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
                    <RecipeFields :recipe="recipe" :categories="data.categories" />
                    <div class="flex justify-end"><SubmitButton>{{ t('Save recipe') }}</SubmitButton></div>
                </ApiForm>
                <div class="flex flex-wrap gap-2 border-t border-line p-4 sm:px-6">
                    <ApiForm :action="`${base}/duplicate`"><SubmitButton variant="secondary" size="sm">{{ t('Make a copy') }}</SubmitButton></ApiForm>
                    <DeleteDialog
                        id="delete-recipe"
                        :title="t('Delete :name?', { name: recipe.name })"
                        :description="t('Its history goes too. Servers keep the copy they ran, and copies other accounts installed stay.')"
                        :action="base"
                        :submit-label="t('Delete recipe')"
                    >
                        <template #trigger="{ open }"><AcmeBtn variant="danger" size="sm" @click="open">{{ t('Delete recipe') }}</AcmeBtn></template>
                    </DeleteDialog>
                </div>
            </template>
            <div v-else class="p-4 sm:p-6"><CodeBlock :code="recipe.script" class="max-h-[32rem] overflow-auto text-xs" /></div>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('History')" :description="t('The latest :count saved versions.', { count: data.keepRevisions })">
            <ul class="divide-y divide-line">
                <li v-for="revision in data.revisions" :key="revision.id" class="px-4 py-3 text-sm sm:px-6">
                    <Disclosure :title="`${changes[revision.change] ?? revision.change} · ${revision.user ?? t('someone')} · ${revision.createdAt ? dateTime(revision.createdAt) : ''}`">
                        <p class="mb-2 text-xs text-muted">{{ revision.name }}<template v-if="revision.description"> · {{ revision.description }}</template></p>
                        <CodeBlock :code="revision.script" class="max-h-80 overflow-auto text-xs" />
                    </Disclosure>
                </li>
            </ul>
        </AcmeCard>
    </div>
</template>
