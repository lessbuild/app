<script setup lang="ts">
import type { PageMeta } from '~/types/site';

/**
 * The public roadmap: what's being built, what's next and what people are asking for (vote with one click when signed
 * in), what shipped lately, and suggesting a feature in a dialog.
 */
definePageMeta({ layout: 'public' });
type Request = { id: number; title: string; description: string | null; votes: number; shippedAt: string | null };
type RoadmapPage = { meta: PageMeta; columns: Array<{ status: 'in_progress' | 'planned' | 'under_review'; requests: Request[] }>; shipped: Request[]; voted: number[]; signedIn: boolean };
const { t, tc, date } = useT();
const { data } = await useApi<RoadmapPage>('/site/roadmap');
usePublicPage(() => data.value.meta);
const headings = computed(() => ({ in_progress: t('In progress'), planned: t('Planned'), under_review: t('Under consideration') }));
const blurbs = computed(() => ({ in_progress: t('Being built now.'), planned: t('Coming next.'), under_review: t('Requests we’re weighing up. Vote for the ones you want.') }));
const busy = ref<number | null>(null);
const pageUrl = useRequestURL().href;

/** Vote for a request, or take the vote back, and show the new count. */
async function vote(request: Request) {
    busy.value = request.id;
    const result = await send<{ message: string }>('POST', `/roadmap/${request.id}/vote`).catch(() => null);
    if (result) {
        flash(result.message);
        await refreshPage();
    }
    busy.value = null;
}
</script>

<template>
    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="max-w-2xl">
                <p class="ui-eyebrow">{{ t('Roadmap') }}</p>
                <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ t('What we’re building') }}</h1>
                <p class="mt-4 text-base leading-7 text-muted">{{ t('Written up from your feedback. Vote for what you want next; you’ll hear from us when it ships.') }}</p>
            </div>
            <UiButton v-if="data.signedIn" variant="primary" :to="{ query: { dialog: 'suggest-feature' } }">{{ t('Suggest a feature') }}</UiButton>
            <UiButton v-else to="/login?redirect=/roadmap">{{ t('Sign in to vote or suggest') }}</UiButton>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <section v-for="column in data.columns" :key="column.status" class="grid gap-3" :aria-labelledby="`column-${column.status}`">
                <div>
                    <h2 :id="`column-${column.status}`" class="flex items-center gap-2 text-lg font-extrabold text-ink">{{ headings[column.status] }} <Badge>{{ column.requests.length }}</Badge></h2>
                    <p class="text-sm text-muted">{{ blurbs[column.status] }}</p>
                </div>
                <article v-for="item in column.requests" :id="`request-${item.id}`" :key="item.id" class="ui-card flex items-start gap-3 p-4">
                    <button
                        v-if="data.signedIn"
                        type="button"
                        :disabled="busy === item.id"
                        :class="['grid min-w-12 place-items-center rounded-control border px-2 py-1.5 text-center transition', data.voted.includes(item.id) ? 'border-primary bg-primary-soft text-primary' : 'border-line text-muted hover:border-primary hover:text-primary']"
                        :aria-pressed="data.voted.includes(item.id)"
                        :aria-label="data.voted.includes(item.id) ? t('Take back your vote for :title', { title: item.title }) : t('Vote for :title', { title: item.title })"
                        @click="vote(item)"
                    >
                        <Icon name="arrow-up-right" class="size-4 -rotate-45" />
                        <span class="text-sm font-extrabold tabular-nums">{{ item.votes }}</span>
                    </button>
                    <span v-else class="grid min-w-12 place-items-center rounded-control border border-line px-2 py-1.5 text-center text-muted">
                        <span class="text-sm font-extrabold tabular-nums">{{ item.votes }}</span>
                        <span class="text-[11px]">{{ tc('vote|votes', item.votes) }}</span>
                    </span>
                    <div class="min-w-0">
                        <h3 class="font-bold text-ink">{{ item.title }}</h3>
                        <p v-if="item.description" class="mt-1 whitespace-pre-line text-sm leading-6 text-muted">{{ item.description }}</p>
                    </div>
                </article>
                <p v-if="column.requests.length === 0" class="rounded-panel border border-dashed border-line p-4 text-sm text-muted">{{ t('Nothing here right now.') }}</p>
            </section>
        </div>

        <section id="shipped" class="grid gap-3" aria-labelledby="shipped-heading">
            <h2 id="shipped-heading" class="text-lg font-extrabold text-ink">{{ t('Recently shipped') }}</h2>
            <div v-for="item in data.shipped" :key="item.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                <Icon name="check" class="size-4 shrink-0 translate-y-0.5 text-success" />
                <span class="font-bold text-ink">{{ item.title }}</span>
                <span class="text-muted">{{ item.shippedAt ? date(item.shippedAt) : '' }} · {{ tc(':count vote|:count votes', item.votes, { count: item.votes }) }}</span>
            </div>
            <p v-if="data.shipped.length === 0" class="text-sm text-muted">{{ t('Nothing shipped from the roadmap in the last 90 days.') }}</p>
            <p class="text-sm"><NuxtLink to="/changelog" class="font-bold text-primary underline">{{ t('Everything that’s changed is in the changelog') }}</NuxtLink></p>
        </section>

        <FormDialog v-if="data.signedIn" id="suggest-feature" :title="t('Suggest a feature')" :description="t('Tell us what you’d like and why. We read every suggestion and write the popular ones up here.')" action="/api/app/feedback" :submit="t('Send suggestion')">
            <input type="hidden" name="kind" value="idea">
            <input type="hidden" name="page" :value="pageUrl">
            <TextareaField id="suggest-feature-message" name="message" :label="t('Your idea')" rows="5" maxlength="5000" required />
        </FormDialog>
    </div>
</template>
