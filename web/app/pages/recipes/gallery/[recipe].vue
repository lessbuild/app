<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A gallery recipe: its script, installing it, favouriting and rating it, and reporting a problem with it. */
definePageMeta({ layout: 'app', area: 'account' });
type GalleryRecipePage = {
    account: { id: string; name: string };
    recipe: { id: number; name: string; category: string; publisher: string; description: string | null; script: string; installs: number; ratings: number; average: number | null; updatedAt: string | null };
    favorited: boolean;
    rating: number | null;
    report: { reason: string; details: string | null; status: string; note: string | null } | null;
    copy: { id: number; updateAvailable: boolean } | null;
    reasons: Option[];
    canInstall: boolean;
    canRate: boolean;
    canReport: boolean;
    canSeeReports: boolean;
};
const { t, tc, number } = useT();
const route = useRoute();
const { data } = await useApi<GalleryRecipePage>(() => `/recipes/gallery/${route.params.recipe}`);
const recipe = computed(() => data.value.recipe);
const base = computed(() => `/api/app/recipes/gallery/${recipe.value.id}`);
const stars = ref<string | null>(String(data.value.rating ?? 5));
const starOptions = [5, 4, 3, 2, 1].map((count) => ({ value: String(count), label: '★'.repeat(count) }));
const reason = ref<string | null>(data.value.report?.reason ?? data.value.reasons[0]?.value ?? null);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="recipe.name" :description="recipe.description ?? t('A gallery recipe.')" />
        <RecipeNav :reports="data.canSeeReports" />

        <section class="ui-card flex flex-wrap items-center justify-between gap-4 p-5">
            <div class="text-sm">
                <p>{{ recipe.category }} · {{ t('by :account', { account: recipe.publisher }) }} · {{ tc(':count install|:count installs', recipe.installs, { count: recipe.installs }) }}</p>
                <p class="text-muted">
                    {{ recipe.ratings > 0 ? t(':average★ from :count ratings', { average: number(recipe.average ?? 0), count: recipe.ratings }) : t('Not rated yet') }}
                    <template v-if="recipe.updatedAt"> · <Rich :text="t('updated :when')"><template #when><RelativeTime :at="recipe.updatedAt" /></template></Rich></template>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <ApiForm :action="`${base}/favorite`" :method="data.favorited ? 'DELETE' : 'PUT'">
                    <SubmitButton variant="quiet" size="sm">{{ data.favorited ? t('Remove from favourites') : t('Add to favourites') }}</SubmitButton>
                </ApiForm>
                <UiButton v-if="data.copy" :to="`/account/recipes/${data.copy.id}`" size="sm">{{ data.copy.updateAvailable ? t('Update your copy') : t('Open your copy') }}</UiButton>
                <ApiForm v-else-if="data.canInstall" :action="`${base}/install`"><SubmitButton size="sm">{{ t('Install into :account', { account: data.account.name }) }}</SubmitButton></ApiForm>
            </div>
        </section>

        <SettingsSection :title="t('Script')" :description="t('Read it first: it runs as root at the end of a new server’s provisioning.')">
            <div class="p-4 sm:p-6"><CodeBlock :code="recipe.script" class="max-h-[32rem] overflow-auto text-xs" /></div>
        </SettingsSection>

        <SettingsSection v-if="data.canRate" :title="t('Your rating')" :description="t('Your account installed it, so your rating helps others choose.')">
            <div class="flex flex-wrap items-end gap-3 p-4 sm:p-6">
                <ApiForm :action="`${base}/rating`" method="PUT" class="flex flex-wrap items-end gap-3">
                    <SelectField v-model="stars" name="rating" :label="t('Stars')" :options="starOptions" />
                    <SubmitButton variant="secondary">{{ data.rating ? t('Change rating') : t('Rate') }}</SubmitButton>
                </ApiForm>
                <ApiForm v-if="data.rating" :action="`${base}/rating`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Withdraw') }}</SubmitButton></ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection
            v-if="data.canReport"
            :title="t('Report a problem')"
            :description="data.report ? t('You reported it (:status). Changing the report opens it again.', { status: data.report.status === 'open' ? t('open') : t('resolved') }) : t('Tell the publisher about a harmful, broken or spam recipe.')"
        >
            <ApiForm :action="`${base}/report`" method="PUT" class="grid items-start gap-4 p-4 sm:grid-cols-2 sm:p-6">
                <SelectField v-model="reason" name="reason" :label="t('Reason')" :options="data.reasons" />
                <div class="sm:col-span-2"><TextareaField name="details" :label="t('Details (optional)')" rows="3" :model-value="data.report?.details" maxlength="2000" /></div>
                <p v-if="data.report?.note" class="text-sm text-muted sm:col-span-2">{{ t('Publisher: :note', { note: data.report.note }) }}</p>
                <div class="sm:col-span-2"><SubmitButton variant="secondary">{{ data.report ? t('Update report') : t('Send report') }}</SubmitButton></div>
            </ApiForm>
            <ApiForm v-if="data.report" :action="`${base}/report`" method="DELETE" class="px-4 pb-4 sm:px-6"><SubmitButton variant="quiet" size="sm">{{ t('Withdraw report') }}</SubmitButton></ApiForm>
        </SettingsSection>
    </div>
</template>
