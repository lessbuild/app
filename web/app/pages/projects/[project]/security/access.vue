<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { AccessGrant, AccessMember, AccessToken } from '~/types/security';

/** Everyone and everything that can reach the account, the access review (a wizard in a dialog) and past reviews. */
definePageMeta({ layout: 'app', service: 'security' });
type Review = { id: number; at: string | null; reviewer: string | null; members: number; tokens: number; removed: string[] };
type AccessPage = {
    overview: ProjectOverview;
    members: AccessMember[];
    tokens: AccessToken[];
    grants: AccessGrant[];
    reviews: Review[];
    dueAfterDays: number;
    requireTwoFactor: boolean;
    included: boolean;
    canManage: boolean;
};
const { t, tc, date } = useT();
const route = useRoute();
const { data } = await useApi<AccessPage>(() => `/projects/${route.params.project}/security/access`);
const project = computed(() => data.value.overview.project);
const canReview = computed(() => data.value.canManage && data.value.included);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Everyone and everything that can reach this account. Review it every :days days; each review is kept as evidence.', { days: data.dueAfterDays })">
            <template v-if="canReview" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'access-review' } }" icon="shield-check">{{ t('Review access') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <PlanNotice v-if="!data.included" :message="t('Access reviews come with the Team Security plan.')" />

        <section class="ui-card overflow-hidden" aria-labelledby="access-members">
            <h2 id="access-members" class="border-b border-line px-5 py-4 text-lg font-semibold text-ink">{{ t('Members') }}</h2>
            <DataTable :caption="t('Members')" :framed="false">
                <template #head><tr><th scope="col">{{ t('Person') }}</th><th scope="col">{{ t('Role') }}</th><th scope="col">{{ t('Two-factor') }}</th><th scope="col">{{ t('Projects') }}</th></tr></template>
                <tr v-for="member in data.members" :key="member.id">
                    <td><span class="font-bold text-ink">{{ member.name }}</span> <span class="block text-xs text-muted">{{ member.email }}</span></td>
                    <td>{{ member.role }}</td>
                    <td><AcmeBadge :tone="acmeTone(member.twoFactor ? 'success' : data.requireTwoFactor ? 'neutral' : 'warning')">{{ member.twoFactor ? t('On') : t('Off') }}</AcmeBadge></td>
                    <td class="text-sm text-muted">{{ member.projects === null ? t('All') : member.projects }}</td>
                </tr>
            </DataTable>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="access-tokens">
            <h2 id="access-tokens" class="border-b border-line px-5 py-4 text-lg font-semibold text-ink">{{ t('API tokens') }}</h2>
            <p v-if="data.tokens.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No API tokens.') }}</p>
            <DataTable v-else :caption="t('API tokens')" :framed="false">
                <template #head><tr><th scope="col">{{ t('Token') }}</th><th scope="col">{{ t('Owner') }}</th><th scope="col">{{ t('Last used') }}</th><th scope="col">{{ t('Expires') }}</th></tr></template>
                <tr v-for="token in data.tokens" :key="token.id">
                    <td><span class="font-bold text-ink">{{ token.name }}</span> <span class="block text-xs text-muted">{{ token.abilities.join(', ') }}</span></td>
                    <td class="text-sm">{{ token.owner ?? '—' }}</td>
                    <td class="text-sm text-muted"><RelativeTime v-if="token.lastUsedAt" :at="token.lastUsedAt" /><template v-else>{{ t('Never') }}</template></td>
                    <td class="text-sm text-muted">{{ token.expiresAt ? date(token.expiresAt) : t('Never') }}</td>
                </tr>
            </DataTable>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="access-ssh">
            <h2 id="access-ssh" class="border-b border-line px-5 py-4 text-lg font-semibold text-ink">{{ t('SSH access') }}</h2>
            <p v-if="data.grants.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No one has personal SSH access to this project’s servers.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="grant in data.grants" :key="grant.id" class="px-5 py-2 text-sm"><span class="font-bold text-ink">{{ grant.name }}</span> <span class="text-muted">· {{ grant.server }}</span></li>
            </ul>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="past-reviews">
            <h2 id="past-reviews" class="border-b border-line px-5 py-4 text-lg font-semibold text-ink">{{ t('Past reviews') }}</h2>
            <p v-if="data.reviews.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No reviews yet.') }}</p>
            <ul v-else class="divide-y divide-line text-sm">
                <li v-for="review in data.reviews" :key="review.id" class="px-5 py-3">
                    <span class="font-bold text-ink">{{ review.at ? date(review.at) : '—' }}</span>
                    <span class="text-muted">
                        · {{ review.reviewer ?? t('Someone') }} · {{ tc(':count member|:count members', review.members, { count: review.members }) }},
                        {{ tc(':count token|:count tokens', review.tokens, { count: review.tokens }) }}<template v-if="review.removed.length"> · {{ t('removed :items', { items: review.removed.join(', ') }) }}</template>
                    </span>
                </li>
            </ul>
        </section>

        <UiDialog v-if="canReview" id="access-review" :title="t('Review access')" :description="t('Tick anything that should go. Nothing is removed until you complete the review.')" size="wide">
            <template #default="{ close }">
                <AccessReviewWizard :project-id="project.id" :members="data.members" :tokens="data.tokens" :grants="data.grants" @done="close" />
            </template>
        </UiDialog>
    </div>
</template>
